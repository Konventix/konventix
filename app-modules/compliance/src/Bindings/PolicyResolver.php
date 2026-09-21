<?php

declare(strict_types=1);

namespace Konventix\Compliance\Bindings;

use Illuminate\Support\Collection;
use Konventix\Compliance\Data\CheckoutLegalTextRoleData;
use Konventix\Compliance\Enums\LawfulBasis;
use Konventix\Compliance\Enums\PolicyBindingKind;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyBinding;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Compliance\Support\CheckoutLegalTexts;
use Konventix\Compliance\Support\OutstandingConsents;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;

/**
 * Which legal texts apply to *this* act (ADR-0066, spec §5.4).
 *
 * `CheckoutLegalTexts::forBrand()` generalised. That method stays exactly as
 * it is and stays the **no-context** call — it is what `missingSlugsForBrands()`
 * and the #222 gap notice ask, and those questions are still brand-wide.
 * This one takes a `BindingContext` and answers the narrower question a
 * purchase path can now ask.
 *
 * **The matching rule (D-CPL-03).** A policy with no bindings always
 * resolves — today's behaviour, unconditionally, which is what makes the
 * whole foundation behaviour-neutral (T-3, D-CPL-04). A bound policy matches
 * when, *for every kind it binds on*, at least one of its bindings of that
 * kind matches the context: **OR within a kind, AND across kinds**. A kind
 * the policy does not bind on places no requirement. A kind it *does* bind
 * on for which the context carries nothing — no provider chosen, no event at
 * a login — cannot match, and the text is not shown there.
 *
 * The sentence this implements and the sentence the console prints are one
 * and the same: *"applies when any of the events match and any of the types
 * match and any of the conditions match and any of the providers match."*
 * That rule narrows, unlike the rest of this model's "resolve towards
 * asking" (T-10); the operator took the narrowing knowingly in exchange for
 * being able to express "this addendum, at this event, through this
 * provider" at all.
 *
 * **What comes back, and in what order (D-CPL-08).** Every matching text,
 * with the version's `requires_consent` deciding agreement versus
 * information. There is no role column and no third role: `terms` and
 * `withdrawal` keep their `CheckoutLegalTextRoleData` description and are
 * listed first, in that order, and every other matching text follows sorted
 * by name.
 *
 * A policy without a published, effective version resolves to nothing — a
 * draft is not a legal text, and a checkout that claimed somebody agreed to
 * one would be claiming agreement to a document that does not exist. That is
 * `CheckoutLegalTexts`' rule too.
 *
 * Stateless — safe as a plain container resolution under Octane. Nothing is
 * cached: a rebinding has to take effect on the next request, and the whole
 * resolution is three queries.
 */
final class PolicyResolver
{
    /**
     * The texts that apply to this act, in the order a surface names them.
     *
     * @return list<ResolvedLegalText>
     */
    public function textsFor(BindingContext $context): array
    {
        return $this->resolve($context, contractOnly: false);
    }

    /**
     * Whether this subject still owes agreement to a **contract** text that
     * applies to this act, and where they settle it (#205, D-CPL-12).
     *
     * The same resolution narrowed twice: to `lawful_basis = contract`, and
     * to the versions this subject holds no live consent for. The answer is
     * a URL rather than a policy for the same reason the checkout slot's
     * `outstandingUrl` is one — the asking module refuses the act without
     * ever learning that a policy exists (ADR-0004).
     *
     * Null is the normal case and means "nothing outstanding here". It never
     * means "we could not tell": a context always names its brand, unlike
     * `OutstandingConsents`, which has to refuse when no brand is resolved
     * at all.
     */
    public function outstandingContractFor(string $subjectId, BindingContext $context): ?string
    {
        $texts = $this->resolve($context, contractOnly: true);

        if ($texts === []) {
            return null;
        }

        $consented = app(OutstandingConsents::class)->consentedVersionIdsFor($subjectId);

        foreach ($texts as $text) {
            if (! in_array($text->versionId, $consented, true)) {
                return route('policies.required', absolute: false);
            }
        }

        return null;
    }

    /**
     * @return list<ResolvedLegalText>
     */
    private function resolve(BindingContext $context, bool $contractOnly): array
    {
        // `Policy::resolvedFor()` already carries the operator-wide
        // shadowing rule (#76): one row per slug, the brand's own where it
        // has one, the operator's everywhere else. Bindings never change
        // which of the two a brand reads — they only decide whether the row
        // that won applies here.
        $policies = Policy::resolvedFor($context->brandId);

        if ($policies->isEmpty()) {
            return [];
        }

        $policyIds = array_values(array_map(strval(...), $policies->modelKeys()));

        $bindingsByPolicy = PolicyBinding::forPolicies($policyIds, $context->brandId)
            ->get()
            ->groupBy('policy_id');

        $versions = $this->currentVersions($policyIds, $contractOnly);

        $texts = [];
        $matchedKinds = [];

        foreach ($policies as $policy) {
            $policyId = (string) $policy->getKey();
            $version = $versions->get($policyId);

            if ($version === null) {
                continue;
            }

            /** @var Collection<int, PolicyBinding> $bindings */
            $bindings = $bindingsByPolicy->get($policyId) ?? new Collection;

            $matched = $this->matchingBindingIds($bindings, $context);

            if ($matched === null) {
                continue;
            }

            foreach ($bindings as $binding) {
                if (in_array((string) $binding->getKey(), $matched, true)) {
                    $matchedKinds[] = $binding->kind->value;
                }
            }

            $texts[] = new ResolvedLegalText(
                slug: $policy->slug,
                name: $policy->name,
                // Root-relative: a brand answers on several domains
                // (ADR-0001).
                url: route('policies.show', ['slug' => $policy->slug], absolute: false),
                versionId: (string) $version->getKey(),
                version: $version->version,
                requiresConsent: (bool) $version->requires_consent,
                scope: $policy->isOperatorWide() ? 'operator' : 'brand',
                role: CheckoutLegalTextRoleData::forSlug($policy->slug)?->description,
                bindingIds: $matched,
            );
        }

        $this->countMatches($context->brandId, $matchedKinds);

        return $this->ordered($texts);
    }

    /**
     * The bindings of one policy that make it apply here, or null when it
     * does not apply at all.
     *
     * An empty binding set returns an empty *list* — applies, bound to
     * nothing — which is not the same as null. Keeping those two apart is
     * the whole of D-CPL-04.
     *
     * @param  Collection<int, PolicyBinding>  $bindings
     * @return list<string>|null
     */
    private function matchingBindingIds(Collection $bindings, BindingContext $context): ?array
    {
        if ($bindings->isEmpty()) {
            return [];
        }

        $matched = [];

        // AND across kinds: every kind the policy binds on must be satisfied.
        foreach ($bindings->groupBy(fn (PolicyBinding $binding): string => $binding->kind->value) as $kindValue => $ofKind) {
            $values = $context->valuesFor(PolicyBindingKind::from((string) $kindValue));

            // OR within a kind — and a bound kind the context knows nothing
            // of can never match, so `$values === []` fails here by itself.
            $hits = $ofKind
                ->filter(fn (PolicyBinding $binding): bool => in_array($binding->target_key, $values, true))
                ->map(fn (PolicyBinding $binding): string => (string) $binding->getKey())
                ->values()
                ->all();

            if ($hits === []) {
                return null;
            }

            $matched = [...$matched, ...$hits];
        }

        return $matched;
    }

    /**
     * The published, effective version of each policy, in one query rather
     * than one per policy — `Policy::currentVersion()`'s predicate, batched.
     *
     * `withoutBrandScope()` for the reason every read path here does it
     * (ADR-0010): an operator-wide policy's versions carry a null `brand_id`
     * and are invisible to the scoped query, and this is also asked from the
     * operator admin host where `BrandScope` hardens to zero rows.
     *
     * @param  list<string>  $policyIds
     * @return Collection<string, PolicyVersion> keyed by policy id
     */
    private function currentVersions(array $policyIds, bool $contractOnly): Collection
    {
        $query = PolicyVersion::withoutBrandScope()
            ->whereIn('policy_id', $policyIds)
            ->where('state', PolicyVersionState::Published)
            ->where('effective_at', '<=', now());

        if ($contractOnly) {
            $query->where('lawful_basis', LawfulBasis::Contract)->where('requires_consent', true);
        }

        return $query->get()->keyBy(fn (PolicyVersion $version): string => (string) $version->policy_id);
    }

    /**
     * `terms`, then `withdrawal`, then everything else by name (D-CPL-08).
     *
     * The two leading slugs are `CheckoutLegalTexts::SLUGS` read in order
     * rather than restated, so a third one added there lands here without
     * anybody remembering to.
     *
     * @param  list<ResolvedLegalText>  $texts
     * @return list<ResolvedLegalText>
     */
    private function ordered(array $texts): array
    {
        $rank = array_flip(CheckoutLegalTexts::SLUGS);

        usort($texts, function (ResolvedLegalText $a, ResolvedLegalText $b) use ($rank): int {
            $rankA = $rank[$a->slug] ?? PHP_INT_MAX;
            $rankB = $rank[$b->slug] ?? PHP_INT_MAX;

            return $rankA === $rankB ? strcasecmp($a->name, $b->name) : $rankA <=> $rankB;
        });

        return $texts;
    }

    /**
     * `policy_binding_matches_total` (ADR-0059, spec §10) — the operator's
     * answer to "is this text actually being asked for?".
     *
     * Counted here rather than from a listener because a resolution is not
     * an event: nothing else in the system knows that a binding matched, and
     * inventing an event per resolution would put one dispatch on every
     * render of every purchase page. `Metrics` reports a storage failure
     * instead of raising it, so this can never break a checkout.
     *
     * The kind is the label; the target key never is (T-11).
     *
     * @param  list<string>  $kinds  one entry per binding that matched
     */
    private function countMatches(string $brandId, array $kinds): void
    {
        if ($kinds === []) {
            return;
        }

        $metrics = app(Metrics::class);
        $brand = BrandSlugs::of($brandId);

        foreach (array_count_values($kinds) as $kind => $count) {
            $metrics->increment('policy_binding_matches_total', ['brand' => $brand, 'kind' => (string) $kind], (float) $count);
        }
    }
}

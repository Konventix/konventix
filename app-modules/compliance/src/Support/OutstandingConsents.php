<?php

declare(strict_types=1);

namespace Konventix\Compliance\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Konventix\Compliance\Enums\LawfulBasis;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Scopes\BrandScope;

/**
 * Which policy versions a subject still owes agreement to, for the brand the
 * request is resolved into.
 *
 * One query shared by the three places that must agree on the answer: the
 * `require-consent` middleware that blocks, the page that asks, and the
 * privacy settings page that explains. Two implementations of "outstanding"
 * would eventually disagree, and the failure mode is either a redirect loop
 * or an unenforced legal requirement.
 *
 * Since GitLab #76 the answer spans two scopes: the brand's own policies and
 * the operator-wide ones it inherits, with a brand's own row shadowing the
 * operator's under the same slug (`Policy::resolvedFor()`). That is why every
 * query here escapes `BrandScope` explicitly and then filters by the resolved
 * policy set instead — an operator-wide row carries no `brand_id` at all and
 * is invisible to the scoped query (ADR-0010).
 *
 * "Outstanding" means: the currently published version of a policy the brand
 * reads, which requires consent, whose effective date has arrived, and for
 * which the subject holds no consent that has not been withdrawn. A withdrawn
 * consent therefore makes the policy outstanding again — which is the point
 * of a required policy, and why the settings page warns before withdrawing
 * one.
 */
final class OutstandingConsents
{
    /**
     * @return Collection<int, PolicyVersion>
     */
    public function forSubject(string $subjectId): Collection
    {
        $required = $this->requiredVersions();

        if ($required === null) {
            return new Collection;
        }

        return $required
            ->whereNotIn('id', $this->consentedVersionIds($subjectId))
            // The relation load escapes the scope too: an operator-wide
            // version's policy row has a null `brand_id`, so the scoped
            // eager load would hand the page a null policy to name
            // (.ai/rules/app-modules-src.md).
            ->with(['policy' => fn ($query) => $query->withoutGlobalScope(BrandScope::class)])
            ->orderBy('created_at')
            ->get();
    }

    public function subjectHasOutstanding(string $subjectId): bool
    {
        $required = $this->requiredVersions();

        if ($required === null) {
            return false;
        }

        return $required->whereNotIn('id', $this->consentedVersionIds($subjectId))->exists();
    }

    /**
     * The same question narrowed to the texts the sale itself rests on
     * (`LawfulBasis::Contract`) — what the login and checkout gates ask
     * (GitLab #205, operator decision of 08.09.2026).
     *
     * "Outstanding" is `requiredVersions()` plus `lawful_basis = contract`:
     * the currently published version of a policy this brand reads, which
     * requires consent, whose effective date has arrived, whose basis is
     * the contract, and for which the subject holds no consent that has not
     * been withdrawn.
     *
     * Consent is recorded against a *version* id, and
     * `PublishPolicyVersion` keeps at most one published effective version
     * per policy — so publishing a new version of a contract text makes
     * exactly that one version outstanding, the next login or checkout asks
     * once, and the row `ConsentController::store()` writes for it silences
     * both gates together. Neither gate can therefore ask twice for the
     * same version, and neither can miss a new one.
     *
     * The wider `subjectHasOutstanding()` stays what the `require-consent`
     * route gate asks: a consent-based required text still blocks the
     * routes that opt into it, it just does not stand between someone and
     * their own account.
     */
    public function subjectHasOutstandingContract(string $subjectId): bool
    {
        $required = $this->requiredVersions();

        if ($required === null) {
            return false;
        }

        return $required
            ->where('lawful_basis', LawfulBasis::Contract)
            ->whereNotIn('id', $this->consentedVersionIds($subjectId))
            ->exists();
    }

    /**
     * The version ids this subject holds a live consent for.
     *
     * The same fact `consentedVersionIds()` expresses as a subquery, read out
     * as a list instead — for the one caller that has already resolved its
     * own candidate set and only needs to subtract
     * (`Bindings\PolicyResolver::outstandingContractFor()`). Kept here rather
     * than re-queried there so "still owes agreement" stays one definition:
     * a consent that has been withdrawn does not count.
     *
     * @return list<string>
     */
    public function consentedVersionIdsFor(string $subjectId): array
    {
        return array_values(array_map(strval(...), $this->consentedVersionIds($subjectId)->pluck('policy_version_id')->all()));
    }

    /**
     * The published, effective, consent-requiring versions of the policies
     * this brand reads — or null when no brand is resolved at all (a console
     * command, a queued job without brand context). Refusing to answer is
     * the safe default: "nothing is outstanding" and "we cannot tell" are
     * different facts, and only the caller knows which one is survivable.
     *
     * @return Builder<PolicyVersion>|null
     */
    private function requiredVersions(): ?Builder
    {
        // Resolved at call time, never constructor-injected: BrandContext is
        // a *scoped* binding, and this service is reached from places that
        // may outlive one request under Octane (.ai/rules/listeners.md).
        $brandId = app(BrandContext::class)->id();

        if ($brandId === null) {
            return null;
        }

        $policyIds = Policy::resolvedFor($brandId)->modelKeys();

        return PolicyVersion::withoutBrandScope()
            ->whereIn('policy_id', $policyIds)
            ->where('state', PolicyVersionState::Published)
            ->where('requires_consent', true)
            ->where('effective_at', '<=', now());
    }

    /**
     * @return Builder<Consent>
     */
    private function consentedVersionIds(string $subjectId)
    {
        return Consent::query()
            ->where('subject_id', $subjectId)
            ->whereNull('withdrawn_at')
            ->select('policy_version_id');
    }
}

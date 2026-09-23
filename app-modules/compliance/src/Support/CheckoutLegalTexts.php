<?php

declare(strict_types=1);

namespace Konventix\Compliance\Support;

use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;

/**
 * The legal texts a public purchase path has to put in front of a payer
 * before the order button, and the one notice that is code-owned rather than
 * editable (GitLab #76, #2).
 *
 * Two slugs, and only two. `terms` is the AGB — the slug the factories, the
 * demo seeder and the registration flow already used — and `withdrawal` is
 * the Widerrufsbelehrung beside it. Naming them here rather than in each
 * caller is what stops a second, parallel set from being invented the next
 * time a purchase path is added; both resolve through
 * `Policy::resolveFor()`, so a brand reads its own text where it published
 * one and the operator's otherwise.
 *
 * The § 312g Abs. 2 Nr. 9 BGB exemption is deliberately *not* a policy
 * version. It is not a text an operator may reword: a leisure-event ticket
 * for a fixed date carries no right of withdrawal by statute, and a page
 * that let an operator edit that sentence would let one silently promise a
 * right the payer does not have — or deny one they do. The editable half is
 * the Widerrufsbelehrung itself; this sentence is the fact.
 *
 * Stateless — safe as a plain container resolution under Octane.
 */
final class CheckoutLegalTexts
{
    /**
     * The AGB and the Widerrufsbelehrung, in the order a checkout names
     * them.
     *
     * @var list<string>
     */
    public const array SLUGS = ['terms', 'withdrawal'];

    /**
     * Shown next to the consent control on every public purchase path.
     *
     * The English source and a catalog key (ADR-0068): whoever renders it
     * passes it through `__()`, so the payer reads it in their own language.
     * The statute keeps its German citation verbatim in every language,
     * because that is its name and translating it would make it unfindable.
     */
    public const string EXEMPTION_NOTICE = 'Tickets for a leisure event on a fixed date carry no right of withdrawal '
        .'(§ 312g Abs. 2 Nr. 9 BGB). Your order is binding as soon as it is placed.';

    /**
     * The purchase texts this brand actually has, in `SLUGS` order — its
     * own where it published one, the operator's otherwise, and absent
     * entirely where nothing is published at all.
     *
     * A fresh instance therefore reports nothing rather than links to pages
     * that 404, which is what keeps a checkout from claiming a payer agreed
     * to a text that does not exist.
     *
     * @return list<array{slug: string, name: string, url: string, versionId: string, version: string, scope: string}>
     */
    public function forBrand(string $brandId): array
    {
        $texts = [];

        foreach (self::SLUGS as $slug) {
            $policy = Policy::resolveFor($brandId, $slug);
            $version = $policy?->currentVersion();

            if ($policy === null || $version === null) {
                continue;
            }

            $texts[] = [
                'slug' => $policy->slug,
                'name' => $policy->name,
                // Root-relative: a brand answers on several domains
                // (ADR-0001), and a link bound to whichever host rendered
                // the page would leave that brand's other domains.
                'url' => route('policies.show', ['slug' => $policy->slug], absolute: false),
                'versionId' => (string) $version->getKey(),
                'version' => $version->version,
                'scope' => $policy->isOperatorWide() ? 'operator' : 'brand',
            ];
        }

        return $texts;
    }

    /**
     * The same question as `forBrand()`, asked for many brands at once and
     * answered from the other side: which of `SLUGS` each brand resolves
     * *nothing* for (#200, fix round 1).
     *
     * The policy console asks this for every brand on every render, and
     * `forBrand()` costs four queries per brand — two policy lookups and two
     * version lookups. This costs two, whatever the number of brands: the
     * candidate policy rows, then their published versions.
     *
     * The shadowing rule is `Policy::resolveFor()`'s, restated over the rows
     * in memory rather than re-queried: a brand's own row wins over the
     * operator-wide one *whether or not it has anything published*, which is
     * exactly the case #200 was filed about — a brand that publishes an `agb`
     * policy and an empty `terms` one silently loses its checkout consent.
     *
     * `withoutBrandScope()` throughout (ADR-0010): this is asked from the
     * operator admin host, where `BrandScope` hardens brand-owned queries to
     * zero rows, and the operator-wide rows carry a null `brand_id` that the
     * scope could never see anyway.
     *
     * @param  list<string>  $brandIds
     * @return array<string, list<string>> brand ULID => the slugs it resolves nothing for, in `SLUGS` order
     */
    public function missingSlugsForBrands(array $brandIds): array
    {
        if ($brandIds === []) {
            return [];
        }

        $policies = Policy::withoutBrandScope()
            ->whereIn('slug', self::SLUGS)
            ->where(function ($query) use ($brandIds): void {
                $query->whereIn('brand_id', $brandIds)->orWhereNull('brand_id');
            })
            ->get(['id', 'brand_id', 'slug']);

        $published = PolicyVersion::withoutBrandScope()
            ->whereIn('policy_id', $policies->pluck('id'))
            ->where('state', PolicyVersionState::Published)
            ->where('effective_at', '<=', now())
            ->pluck('policy_id')
            ->flip();

        /** @var array<string, Policy> $operatorWide slug => the operator's own row */
        $operatorWide = [];
        /** @var array<string, array<string, Policy>> $ownedByBrand brand ULID => slug => that brand's own row */
        $ownedByBrand = [];

        foreach ($policies as $policy) {
            if ($policy->brand_id === null) {
                $operatorWide[$policy->slug] = $policy;

                continue;
            }

            $ownedByBrand[$policy->brand_id][$policy->slug] = $policy;
        }

        $missing = [];

        foreach ($brandIds as $brandId) {
            $gaps = [];

            foreach (self::SLUGS as $slug) {
                // The brand's own row shadows the operator's even when it has
                // nothing published — that is what makes it a gap.
                $resolved = $ownedByBrand[$brandId][$slug] ?? $operatorWide[$slug] ?? null;

                if ($resolved === null || ! $published->has($resolved->getKey())) {
                    $gaps[] = $slug;
                }
            }

            if ($gaps !== []) {
                $missing[$brandId] = $gaps;
            }
        }

        return $missing;
    }
}

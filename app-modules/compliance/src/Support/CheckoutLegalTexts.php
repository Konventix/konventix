<?php

declare(strict_types=1);

namespace Konventix\Compliance\Support;

use Konventix\Compliance\Models\Policy;

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
     * English because the purchase pages are English today (#132); the
     * statute keeps its German citation verbatim, because that is its name
     * and translating it would make it unfindable.
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
}

<?php

declare(strict_types=1);

namespace Konventix\Compliance\Legal;

use Konventix\Compliance\Models\Policy;
use Konventix\Platform\Legal\PublicLegalTexts;

/**
 * Compliance's answer to Platform's `PublicLegalTexts` contract (#184): the
 * URL of a brand's published text under a slug, resolved through
 * `Policy::resolveFor()` so the brand's own row shadows the operator's by
 * exactly the rule every other reader uses.
 *
 * Null where the policy does not exist, or exists with nothing published and
 * effective — `Policy::currentVersion()` decides, the same as
 * `CheckoutLegalTexts::forBrand()`. That null is the point: the caller (the
 * Art. 13 DSGVO sentence on the registration page) renders no link rather
 * than a link that 404s.
 *
 * Root-relative, because a brand answers on several domains (ADR-0001) and a
 * link bound to whichever host rendered the page would leave the others.
 *
 * Stateless — safe as a singleton under Octane.
 */
final class PolicyPublicLegalTexts implements PublicLegalTexts
{
    public function urlFor(string $brandId, string $slug): ?string
    {
        $policy = Policy::resolveFor($brandId, $slug);

        if ($policy === null || $policy->currentVersion() === null) {
            return null;
        }

        return route('policies.show', ['slug' => $policy->slug], absolute: false);
    }
}

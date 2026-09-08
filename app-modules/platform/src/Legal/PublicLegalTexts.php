<?php

declare(strict_types=1);

namespace Konventix\Platform\Legal;

/**
 * Where a brand's published legal text lives, for code that must *link* to
 * one without knowing what a policy is (#184).
 *
 * Compliance owns policies and their versions; Platform is the kernel every
 * module may import, and the reverse is forbidden (ADR-0004). So the shape
 * ADR-0047 point 5 already established for the waiting room repeats here:
 * Platform declares the contract, Compliance binds the implementation, and
 * Platform's own code resolves the contract. Nothing in Platform ever names
 * a `Policy`.
 *
 * The one caller today is `OperatorPresence`, which needs the privacy
 * policy's URL for the Art. 13 DSGVO sentence on the registration page —
 * and needs `null` where nothing is published, because a link to a text
 * that 404s is worse on that sentence than no link at all.
 */
interface PublicLegalTexts
{
    /**
     * The public URL of the text this brand resolves under `$slug` — its
     * own where it published one, the operator's otherwise — or null where
     * nothing published resolves.
     *
     * Root-relative, like every other brand-scoped legal link: a brand
     * answers on several domains (ADR-0001).
     */
    public function urlFor(string $brandId, string $slug): ?string;
}

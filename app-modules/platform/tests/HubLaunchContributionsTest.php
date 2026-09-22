<?php

declare(strict_types=1);

use Konventix\Platform\Hub\HubRegistry;

/**
 * D-HUB-05: the hub is not empty on the first day. Four modules contribute
 * what an admitted participant reaches for first, and they contribute it
 * from their own providers (ADR-0003) — so the one thing worth pinning
 * centrally is that boot actually produced all four keys.
 *
 * `all()` rather than `visibleCards()`/`visibleLinks()`: two of the four
 * belong to gateable modules, and whether a brand has switched one on is
 * `HubRegistryTest`'s subject, not this one's.
 */
it('registers the four launch contributions', function (): void {
    $this->withBrand();

    $keys = app(HubRegistry::class)->all()->pluck('key')->all();

    expect($keys)->toContain('admission.my-ticket', 'content.announcements', 'resources.my-seat', 'support.help');
});

<?php

declare(strict_types=1);

namespace Konventix\Messaging\Actions;

use Konventix\Messaging\Models\BrandMailSettings;

/**
 * The IANA timezone a brand's payer-facing instants should render in — the
 * same brand-level setting `Digests\HoldPolicy` already reads to place
 * quiet hours, exposed here (rather than left `private` on that class) so a
 * mail anywhere in the tree can render a deadline in the brand's own zone
 * instead of the application's (`config('app.timezone')`, UTC). Falls back
 * to that application timezone for a brand with no Messaging settings row.
 *
 * This is an `Actions` class, not a model: cross-module callers may import
 * it under the ArchitectureTest allow-list the same way they already call
 * `SendMessage`, without touching Messaging's tables directly.
 */
final class ResolveBrandTimezone
{
    public function handle(string $brandId): string
    {
        return BrandMailSettings::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->value('timezone')
            ?? (string) config('app.timezone');
    }
}

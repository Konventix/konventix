<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Konventix\Platform\Features\BrandFeatureRegistry;
use Konventix\Platform\Models\Brand;
use Laravel\Pennant\Feature;

/**
 * Sets which per-brand features (ADR-0047) are active for one brand —
 * mirrors `UpdateBrandModules`, keyed by feature name instead of module.
 * Unknown names are ignored, not errors, so a stale or forged form changes
 * nothing it should not.
 */
final class UpdateBrandFeatures
{
    public function __construct(private readonly BrandFeatureRegistry $registry) {}

    /**
     * @param  array<string, bool>  $features  feature name => active
     */
    public function handle(Brand $brand, array $features): void
    {
        $known = collect($this->registry->all())->pluck('name')->all();

        foreach ($features as $name => $active) {
            if (! in_array($name, $known, true)) {
                continue;
            }

            if ($active) {
                Feature::for($brand)->activate($name);
            } else {
                Feature::for($brand)->deactivate($name);
            }
        }
    }
}

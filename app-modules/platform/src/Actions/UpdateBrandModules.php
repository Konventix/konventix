<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Konventix\Platform\Models\Brand;
use Konventix\Platform\Modules\ModuleGate;
use Laravel\Pennant\Feature;

/**
 * Sets which gateable modules are active for one brand (ADR-0003) — the
 * data behind all four enforcement points (routes, nav, slots, listeners).
 * Kernel and Core modules are always on and cannot appear here; unknown or
 * non-gateable keys are ignored, not errors, so a stale or forged form
 * changes nothing it should not.
 */
final class UpdateBrandModules
{
    public function __construct(private readonly ModuleGate $gate) {}

    /**
     * @param  array<string, bool>  $modules  module name => active
     */
    public function handle(Brand $brand, array $modules): void
    {
        $gateable = $this->gate->gateableModuleNames();

        foreach ($modules as $module => $active) {
            if (! in_array($module, $gateable, true)) {
                continue;
            }

            $feature = $this->gate->featureName($module);

            if ($active) {
                Feature::for($brand)->activate($feature);
            } else {
                Feature::for($brand)->deactivate($feature);
            }
        }
    }
}

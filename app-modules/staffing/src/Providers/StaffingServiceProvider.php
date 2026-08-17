<?php

declare(strict_types=1);

namespace Konventix\Staffing\Providers;

use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Staffing\Gdpr\StaffingGdprHandler;

/**
 * Registers everything this module owns. Registration is unconditional and
 * happens at boot; whether the module is *active* is a per-brand Pennant gate
 * applied at four points — routes, navigation, UI slots, listeners (ADR-0003).
 *
 * Never move any of this wiring into a central provider.
 */
class StaffingServiceProvider extends ModuleServiceProvider
{
    /**
     * The module's Pennant feature name and route-middleware key.
     */
    public static function moduleName(): string
    {
        return 'staffing';
    }

    /**
     * Registered unconditionally although the module is Pennant-gated:
     * whether a brand runs Staffing today has no bearing on rows it wrote
     * yesterday (ADR-0003 — registration is unconditional, activation is not).
     */
    protected function registerGdprExporters(): void
    {
        $this->app->make(GdprHandlerRegistry::class)->register(new StaffingGdprHandler);
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Compliance\Providers;

use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;

/**
 * Registers everything this module owns. Registration is unconditional and
 * happens at boot; whether the module is *active* is a per-brand Pennant gate
 * applied at four points — routes, navigation, UI slots, listeners (ADR-0003).
 *
 * Never move any of this wiring into a central provider.
 */
class ComplianceServiceProvider extends ModuleServiceProvider
{
    /**
     * Kernel module: always active for every brand, never gated.
     */
    protected bool $gateable = false;

    /**
     * The module's Pennant feature name and route-middleware key.
     */
    public static function moduleName(): string
    {
        return 'compliance';
    }

    /**
     * GdprHandlerRegistry holds only boot-time registrations (ADR-0003),
     * which a long-lived Octane worker may safely share across requests —
     * same reasoning as Platform's NavigationRegistry.
     */
    protected function registerBindings(): void
    {
        $this->app->singleton(GdprHandlerRegistry::class);
    }
}

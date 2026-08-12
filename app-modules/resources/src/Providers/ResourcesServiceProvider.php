<?php

declare(strict_types=1);

namespace Konventix\Resources\Providers;

use Konventix\Platform\Modules\ModuleServiceProvider;

/**
 * Registers everything this module owns. Registration is unconditional and
 * happens at boot; whether the module is *active* is a per-brand Pennant gate
 * applied at four points — routes, navigation, UI slots, listeners (ADR-0003).
 *
 * Never move any of this wiring into a central provider.
 */
class ResourcesServiceProvider extends ModuleServiceProvider
{
    /**
     * The module's Pennant feature name and route-middleware key.
     */
    public static function moduleName(): string
    {
        return 'resources';
    }
}

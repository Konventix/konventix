<?php

declare(strict_types=1);

namespace Konventix\Platform\Modules\Concerns;

use Konventix\Platform\Modules\ModuleGate;

/**
 * The fourth enforcement point of ADR-0003, packaged for listeners.
 *
 * A gateable module's listener on another module's event must do nothing when
 * the module is off for the brand the event ran under. Queue workers restore
 * brand context before the listener runs (Platform's queue propagation), so
 * the gate resolves against the brand that emitted the event.
 *
 * Usage — first line of handle():
 *
 *     use ShortCircuitsInactiveModule;
 *
 *     public function handle(RegistrationConfirmed $event): void
 *     {
 *         if ($this->moduleIsInactive()) {
 *             return;
 *         }
 *         // ...
 *     }
 *
 *     public function handle(RegistrationConfirmed $event): void
 *     {
 *         if (! $this->moduleActiveForBrand($event->brandId)) {
 *             return;
 *         }
 *         // ...
 *     }
 *
 * The module name comes from the listener's own module ServiceProvider name;
 * implement moduleName() with the same kebab-case string.
 */
trait ShortCircuitsInactiveModule
{
    /**
     * The kebab-case module this listener belongs to — identical to the
     * ServiceProvider's moduleName().
     */
    abstract protected function moduleName(): string;

    protected function moduleIsInactive(): bool
    {
        return ! app(ModuleGate::class)->isActive($this->moduleName());
    }

    /**
     * The gate for events that fire with no brand in context — a webhook, the
     * scheduler — where the listener hands over the brand id its payload
     * carries. Same answer as `moduleIsInactive()` for the same brand; this
     * one just does not need the middleware to have set it.
     */
    protected function moduleActiveForBrand(string $brandId): bool
    {
        return app(ModuleGate::class)->isActiveForBrand($this->moduleName(), $brandId);
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Registration\Providers;

use Illuminate\Support\Facades\Event;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Slots\SlotRegistry;
use Konventix\Registration\Listeners\RecordPaymentOnOrderPaid;

/**
 * Registers everything this module owns. Registration is unconditional and
 * happens at boot; whether the module is *active* is a per-brand Pennant gate
 * applied at four points — routes, navigation, UI slots, listeners (ADR-0003).
 *
 * Never move any of this wiring into a central provider.
 */
class RegistrationServiceProvider extends ModuleServiceProvider
{
    /**
     * Core module: always active for every brand, never gated.
     */
    protected bool $gateable = false;

    /**
     * The module's Pennant feature name and route-middleware key.
     */
    public static function moduleName(): string
    {
        return 'registration';
    }

    /**
     * Registration learns payment outcomes from Commerce's events, never
     * from Commerce models (ADR-0006, SPEC.md "Consumes").
     */
    protected function registerListeners(): void
    {
        Event::listen(OrderPaid::class, RecordPaymentOnOrderPaid::class);
    }

    /**
     * Contributes the `registration/EventStatsCard` card to Event's
     * `event.admin.dashboard` UI slot (ADR-0003 enforcement point 3,
     * docs/decisions/0003). The component fetches its own numbers from
     * `RegistrationStatsController` — Event never queries this module's
     * models directly.
     */
    protected function registerSlots(): void
    {
        $this->app->make(SlotRegistry::class)->add(
            slot: 'event.admin.dashboard',
            module: 'registration',
            component: 'registration/EventStatsCard',
            props: [],
            order: 10,
        );
    }
}

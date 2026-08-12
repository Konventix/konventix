<?php

declare(strict_types=1);

namespace Konventix\Commerce\Providers;

use Illuminate\Support\Facades\Event;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Listeners\CreateOrderOnSettlementRequested;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Registration\Events\RegistrationSettlementRequested;

/**
 * Registers everything this module owns. Registration is unconditional and
 * happens at boot; whether the module is *active* is a per-brand Pennant gate
 * applied at four points — routes, navigation, UI slots, listeners (ADR-0003).
 *
 * Never move any of this wiring into a central provider.
 */
class CommerceServiceProvider extends ModuleServiceProvider
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
        return 'commerce';
    }

    /**
     * Commerce participates exactly when a registration type declares a
     * settlement that moves money (ADR-0026): this listener is the module's
     * entire inbound surface today.
     */
    protected function registerListeners(): void
    {
        Event::listen(RegistrationSettlementRequested::class, CreateOrderOnSettlementRequested::class);
    }

    protected function registerNavigation(): void
    {
        $this->app->make(NavigationRegistry::class)->add(
            new NavigationItem(
                module: 'commerce',
                label: 'Orders',
                url: '/admin/orders',
                icon: 'receipt',
                order: 25,
                permission: Permission::ViewOrders->value,
                area: NavigationItem::AREA_ADMIN,
            ),
        );
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Event\Providers;

use Konventix\Event\Blocks\CountdownBlock;
use Konventix\Event\Blocks\EventHeroBlock;
use Konventix\Event\Enums\Permission;
use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;

/**
 * Registers everything this module owns. Registration is unconditional and
 * happens at boot; whether the module is *active* is a per-brand Pennant gate
 * applied at four points — routes, navigation, UI slots, listeners (ADR-0003).
 *
 * Never move any of this wiring into a central provider.
 */
class EventServiceProvider extends ModuleServiceProvider
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
        return 'event';
    }

    /**
     * Event's static-page block types (ADR-0003's cross-module proof: a
     * module contributes to Platform's block registry through the same
     * `registerBlockTypes()` hook Platform's own `text`/`hero`/`cards`
     * blocks use, without Platform knowing Event exists).
     */
    protected function registerBlockTypes(): void
    {
        $registry = $this->app->make(BlockRegistry::class);

        $registry->register(new EventHeroBlock);
        $registry->register(new CountdownBlock);
    }

    /**
     * Operator-console entry (ADR-0029) for the per-event admin dashboard
     * skeleton. Kept as its own method, separate from `registerBlockTypes()`
     * above, so the two hooks never collide when touched independently.
     *
     * `icon: 'calendar'` is a deliberate deviation: `AdminLayout`'s
     * `iconMap` is out of scope for this change, so the entry renders
     * without an icon until that map is extended — the template already
     * tolerates an unknown icon key.
     */
    protected function registerNavigation(): void
    {
        $this->app->make(NavigationRegistry::class)->add(
            new NavigationItem(
                module: 'event',
                label: 'Events',
                url: '/admin/events',
                icon: 'calendar',
                order: 15,
                permission: Permission::ViewEvents->value,
                area: NavigationItem::AREA_ADMIN,
            ),
            new NavigationItem(
                module: 'event',
                label: 'Venues',
                url: '/admin/venues',
                icon: 'map-pin',
                order: 16,
                permission: Permission::ViewVenues->value,
                area: NavigationItem::AREA_ADMIN,
            ),
        );
    }
}

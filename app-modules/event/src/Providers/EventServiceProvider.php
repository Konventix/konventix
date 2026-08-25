<?php

declare(strict_types=1);

namespace Konventix\Event\Providers;

use Illuminate\Database\Eloquent\Builder;
use Konventix\Event\Blocks\CountdownBlock;
use Konventix\Event\Blocks\EventHeroBlock;
use Konventix\Event\Enums\Permission;
use Konventix\Event\Models\Event;
use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Operators\ConsoleEventSources;

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
     * Two shells, one registry (ADR-0029): the brand topbar gets the public
     * event list, the operator console gets event and venue management. The
     * brand entry carries no `permission` because the list it points at is
     * public — a visitor with no account must see it.
     */
    protected function registerNavigation(): void
    {
        $this->app->make(NavigationRegistry::class)->add(
            new NavigationItem(
                module: 'event',
                label: 'Events',
                url: '/events',
                icon: 'calendar',
                order: 10,
                area: NavigationItem::AREA_BRAND,
            ),
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

    /**
     * The console's event selector (slice 18). Unarchived events only —
     * archived means put away — newest first, so the event an operator is
     * working on is at the top.
     */
    protected function registerConsoleEventSources(): void
    {
        $this->app->make(ConsoleEventSources::class)->add(
            static fn (?string $brandId): array => array_values(
                Event::withoutBrandScope()
                    ->whereNull('archived_at')
                    ->when($brandId !== null, fn (Builder $query) => $query->forBrand($brandId))
                    ->orderByDesc('starts_at')
                    ->get(['id', 'name', 'brand_id'])
                    ->map(fn (Event $event): array => [
                        'id' => (string) $event->getKey(),
                        'name' => $event->name,
                        'brandId' => (string) $event->brand_id,
                    ])
                    ->all(),
            ),
        );
    }
}

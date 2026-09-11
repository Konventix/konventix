<?php

declare(strict_types=1);

namespace Konventix\Event\Providers;

use Illuminate\Database\Eloquent\Builder;
use Konventix\Event\Blocks\CountdownBlock;
use Konventix\Event\Blocks\EventHeroBlock;
use Konventix\Event\Console\ReplayEventDetailsCommand;
use Konventix\Event\Enums\Permission;
use Konventix\Event\Models\Event;
use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Operators\ConsoleEventSources;
use Konventix\Platform\Slots\SlotRegistry;

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
     * Registered explicitly: modular discovers nothing under a module's
     * `src/Console`, and a replay command that silently failed to register
     * would look like "nothing to backfill". Console only — a web worker
     * never needs the command class loaded.
     */
    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([ReplayEventDetailsCommand::class]);
        }
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
     * Spec §7: the landing page has no action button today, so this is the
     * first entry `event.landing` carries. Styled after the seat picker's
     * primary-action link in Resources (`SeatSummarySlot.vue`'s "Choose
     * your seat"), so a visitor sees one consistent button treatment
     * across both public and account-page surfaces. No `feature:` gate:
     * Event is Kernel (`$gateable = false` above), never switched off.
     */
    protected function registerSlots(): void
    {
        $slots = $this->app->make(SlotRegistry::class);

        $slots->add(
            slot: 'event.landing',
            module: 'event',
            component: 'event/CalendarLink',
            props: static fn (array $context): array => [
                'eventSlug' => $context['eventSlug'],
            ],
            // After Registration's offers (order: 10, RegistrationServiceProvider)
            // — "add to calendar" is a secondary action once a visitor has
            // seen what is on sale, not the page's first call to action.
            order: 20,
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

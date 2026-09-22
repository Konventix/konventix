<?php

declare(strict_types=1);

namespace Konventix\Agenda\Providers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;
use Konventix\Agenda\Data\NowNextCardData;
use Konventix\Agenda\Hub\NowNextCard;
use Konventix\Agenda\Listeners\ProjectEventSummary;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDaysChanged;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Platform\Data\ActiveEventData;
use Konventix\Platform\Hub\HubCard;
use Konventix\Platform\Hub\HubRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;

/**
 * Registers everything this module owns. Registration is unconditional and
 * happens at boot; whether the module is *active* is a per-brand Pennant gate
 * applied at four points — routes, navigation, UI slots, listeners (ADR-0003).
 *
 * Never move any of this wiring into a central provider.
 */
class AgendaServiceProvider extends ModuleServiceProvider
{
    /**
     * The module's Pennant feature name and route-middleware key.
     */
    public static function moduleName(): string
    {
        return 'agenda';
    }

    /**
     * Agenda's read model of the four event facts the programme needs — the
     * slug the public URL resolves by, the name, the timezone every wall
     * clock is promised in (ADR-0027) and the day set the switcher is built
     * from. This module may not read Event's tables (ADR-0004), so these
     * projections are the only path by which any of it arrives.
     *
     * Deliberately **not** short-circuited on the Pennant gate, unlike a
     * listener that *does* something: a brand that switches Agenda on
     * halfway through planning must find its events already here, or every
     * programme editor would 404. `Konventix\Resources\Listeners\
     * ProjectEventWindow` makes the same call for the same reason.
     */
    protected function registerListeners(): void
    {
        Event::listen(EventCreated::class, [ProjectEventSummary::class, 'handleCreated']);
        Event::listen(EventDetailsUpdated::class, [ProjectEventSummary::class, 'handleDetailsUpdated']);
        Event::listen(EventRescheduled::class, [ProjectEventSummary::class, 'handleRescheduled']);
        Event::listen(EventDaysChanged::class, [ProjectEventSummary::class, 'handleDaysChanged']);
    }

    /**
     * The two questions a participant standing in a hall actually has
     * (design §6): what is running now, what starts next. Order 20, behind
     * Admission's ticket — the code the door scans is what somebody opens
     * the hub for first.
     *
     * No `permission`, and none is missing: the programme is public, so
     * there is no capability to hold. The card is on the hub rather than
     * merely on the public page because the hub is the page an admitted
     * participant already has open.
     *
     * `static fn`, and `NowNextCard` resolved inside it: the registry is a
     * singleton that outlives the request under Octane, and a closure
     * capturing `$this` would pin this provider — with its container — in
     * the worker's memory (`.ai/rules/providers.md`).
     */
    protected function registerHub(): void
    {
        $this->app->make(HubRegistry::class)->add(new HubCard(
            module: 'agenda',
            key: 'agenda.now-next',
            label: 'Jetzt / Gleich',
            component: 'agenda/hub/NowNextCard',
            payload: static fn (ActiveEventData $event, Authenticatable $user): NowNextCardData => app(NowNextCard::class)($event, $user),
            order: 20,
        ));
    }
}

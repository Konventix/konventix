<?php

declare(strict_types=1);

namespace Konventix\Agenda\Providers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;
use Konventix\Agenda\Data\NowNextCardData;
use Konventix\Agenda\Enums\Permission;
use Konventix\Agenda\Events\AgendaItemPublished;
use Konventix\Agenda\Hub\NowNextCard;
use Konventix\Agenda\Listeners\Metrics\RecordAgendaMetrics;
use Konventix\Agenda\Listeners\ProjectEventSummary;
use Konventix\Agenda\Metrics\AgendaMetrics;
use Konventix\Event\Events\EventAnnounced;
use Konventix\Event\Events\EventArchived;
use Konventix\Event\Events\EventCancelled;
use Konventix\Event\Events\EventConcluded;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDaysChanged;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventPublished;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Events\EventStarted;
use Konventix\Event\Events\EventUnpublished;
use Konventix\Platform\Data\ActiveEventData;
use Konventix\Platform\Hub\HubCard;
use Konventix\Platform\Hub\HubRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Observability\MetricCatalog;
use Konventix\Platform\Permissions\DefaultRole;

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

    public function boot(): void
    {
        parent::boot();

        $this->registerMetrics();
    }

    /**
     * Agenda's one metric (ADR-0059, design §8), registered the way every
     * module registers its own: the catalogue entry here, the recorder in
     * `registerListeners()`. The host never learns this name — it asks the
     * catalogue.
     */
    protected function registerMetrics(): void
    {
        $this->app->make(MetricCatalog::class)->register(...AgendaMetrics::definitions());
    }

    /**
     * ADR-0045, design §8: one starting point per brand, because there is
     * one capability. The person who composes the published programme —
     * items, tracks, places, and in half two the competition selection —
     * is one job, and `Permission` has exactly one case for it.
     *
     * The design says "granted to the Event Admin default role"; it is
     * granted here instead, and that is the same grant. A default role may
     * only carry its own module's capabilities (`DefaultRole::of()` refuses
     * anything else — ADR-0013, Platform may not name another module's
     * enum), Event ships no default role at all, and Platform's Brand Admin
     * is `everything` and therefore already holds `agenda.manage-agenda`.
     * What was missing was a grant an operator can hand to somebody who is
     * *not* the brand admin, which is this one.
     *
     * Materialized per brand by `permissions:sync` and on activation; a row
     * the operator has since edited is never rewritten.
     *
     * @return list<DefaultRole>
     */
    public function defaultRoles(): array
    {
        return [
            DefaultRole::of('agenda.programme-editor', 'Programme Editor', Permission::ManageAgenda),
        ];
    }

    /**
     * Agenda's read model of the five event facts the programme needs — the
     * slug the public URL resolves by, the name, the timezone every wall
     * clock is promised in (ADR-0027), the day set the switcher is built
     * from, and the lifecycle state that decides whether the public page
     * resolves at all. This module may not read Event's tables (ADR-0004),
     * so these projections are the only path by which any of it arrives.
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

        // The event's own lifecycle, which decides whether the *public*
        // programme page resolves at all: a draft event's schedule must not
        // be reachable by guessing a slug. One handler, seven events —
        // each of them is a state rather than carrying one.
        Event::listen(EventAnnounced::class, [ProjectEventSummary::class, 'handleLifecycle']);
        Event::listen(EventPublished::class, [ProjectEventSummary::class, 'handleLifecycle']);
        Event::listen(EventUnpublished::class, [ProjectEventSummary::class, 'handleLifecycle']);
        Event::listen(EventStarted::class, [ProjectEventSummary::class, 'handleLifecycle']);
        Event::listen(EventConcluded::class, [ProjectEventSummary::class, 'handleLifecycle']);
        Event::listen(EventCancelled::class, [ProjectEventSummary::class, 'handleLifecycle']);
        Event::listen(EventArchived::class, [ProjectEventSummary::class, 'handleLifecycle']);

        // The counter behind the platform dashboard's "Programme items
        // published" (ADR-0059). No gate check: `AgendaItemPublished` is
        // this module's own event and cannot fire for a brand that has the
        // module off.
        Event::listen(AgendaItemPublished::class, [RecordAgendaMetrics::class, 'handleItemPublished']);
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

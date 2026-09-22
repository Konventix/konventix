<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Event\Actions\AssignVenue;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Events\EventAnnounced;
use Konventix\Event\Events\EventArchived;
use Konventix\Event\Events\EventCancelled;
use Konventix\Event\Events\EventConcluded;
use Konventix\Event\Events\EventDaysChanged;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventPublished;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Events\EventStarted;
use Konventix\Event\Events\EventUnpublished;
use Konventix\Event\Events\VenueAssigned;
use Konventix\Event\Models\Event as EventModel;
use Konventix\Event\Models\EventDay;
use Konventix\Event\Models\Venue;

/**
 * `event:replay-details` re-emits the two display-and-schedule contract
 * events with each event's *current* values, so a read model that came
 * later than the event — Resources' `resource_event_windows` after the
 * module is switched on for a brand — can be rebuilt from the contract
 * instead of from a cross-module query (ADR-0004). Same idea as
 * `identity:replay-profiles`.
 */
beforeEach(function (): void {
    $this->withBrand();
});

it('re-emits the schedule and the details of every event with its current values', function (): void {
    $berlin = EventModel::factory()->create([
        'starts_at' => '2027-06-15 10:00:00',
        'ends_at' => '2027-06-17 18:00:00',
        'timezone' => 'Europe/Berlin',
    ]);
    $other = EventModel::factory()->create();

    Event::fake([EventRescheduled::class, EventDetailsUpdated::class]);

    $this->artisan('event:replay-details')
        ->expectsOutputToContain('Replayed 2 event(s).')
        ->assertSuccessful();

    Event::assertDispatchedTimes(EventRescheduled::class, 2);
    Event::assertDispatchedTimes(EventDetailsUpdated::class, 2);

    Event::assertDispatched(
        EventRescheduled::class,
        fn (EventRescheduled $e): bool => $e->eventId === $berlin->id
            && $e->brandId === $berlin->brand_id
            && $e->startsAt === '2027-06-15 10:00:00'
            && $e->endsAt === '2027-06-17 18:00:00'
            && $e->timezone === 'Europe/Berlin',
    );
    Event::assertDispatched(
        EventDetailsUpdated::class,
        fn (EventDetailsUpdated $e): bool => $e->eventId === $berlin->id
            && $e->brandId === $berlin->brand_id
            && $e->name === $berlin->name
            && $e->slug === $berlin->slug
            && $e->visibility === $berlin->visibility->value,
    );
    Event::assertDispatched(
        EventRescheduled::class,
        fn (EventRescheduled $e): bool => $e->eventId === $other->id,
    );
});

it('emits the schedule, then the days, then the details, so a window exists before it is named', function (): void {
    EventModel::factory()->create();

    $order = [];
    Event::listen(EventRescheduled::class, function () use (&$order): void {
        $order[] = 'rescheduled';
    });
    Event::listen(EventDaysChanged::class, function () use (&$order): void {
        $order[] = 'days';
    });
    Event::listen(EventDetailsUpdated::class, function () use (&$order): void {
        $order[] = 'details';
    });

    $this->artisan('event:replay-details')->assertSuccessful();

    expect($order)->toBe(['rescheduled', 'days', 'details']);
});

it('replays a single event with --event', function (): void {
    $wanted = EventModel::factory()->create();
    EventModel::factory()->create();

    Event::fake([EventRescheduled::class, EventDetailsUpdated::class]);

    $this->artisan('event:replay-details', ['--event' => $wanted->id])
        ->expectsOutputToContain('Replayed 1 event(s).')
        ->assertSuccessful();

    Event::assertDispatchedTimes(EventRescheduled::class, 1);
    Event::assertDispatched(EventRescheduled::class, fn (EventRescheduled $e): bool => $e->eventId === $wanted->id);
});

it('fails for an --event id that does not exist', function (): void {
    Event::fake([EventRescheduled::class, EventDetailsUpdated::class]);

    $this->artisan('event:replay-details', ['--event' => (string) Str::ulid()])
        ->assertFailed();

    Event::assertNotDispatched(EventRescheduled::class);
});

it('re-emits VenueAssigned for the primary venue and nothing for an event without one', function (): void {
    $this->withBrand();
    $withVenue = EventModel::factory()->create();
    $venue = Venue::factory()->create(['name' => 'Congress Centrum', 'city' => 'Hamburg']);
    app(AssignVenue::class)->handle($withVenue, $venue, isPrimary: true);

    $withoutVenue = EventModel::factory()->create();

    Event::fake([EventRescheduled::class, EventDetailsUpdated::class, VenueAssigned::class]);

    $this->artisan('event:replay-details')->assertSuccessful();

    Event::assertDispatchedTimes(VenueAssigned::class, 1);
    Event::assertDispatched(
        VenueAssigned::class,
        fn (VenueAssigned $e): bool => $e->eventId === $withVenue->id
            && $e->venueId === $venue->id
            && $e->brandId === $withVenue->brand_id
            && $e->name === 'Congress Centrum'
            && $e->city === 'Hamburg'
            && $e->isPrimary === true,
    );
    expect($withoutVenue)->not->toBeNull(); // the second event exists; VenueAssigned fired only once above.
});

/**
 * `EventDaysChanged` is replayed unconditionally, like the schedule and the
 * details and unlike the lifecycle events behind `--with-state`: it carries
 * the complete current day set rather than a transition, and both its
 * consumers replace their own copy of it wholesale. Without it, Agenda's
 * `agenda_event_summaries.days` and Admission's
 * `entrance_event_profiles.days` stay `NULL` for every event that predates
 * their module — a public programme that renders empty however many items
 * are published, and a door with no calendar to resolve a scan against.
 */
it('re-emits the day set in sequence order, with the fields SyncEventDays sends', function (): void {
    $event = EventModel::factory()->create();
    // Created out of order on purpose: the payload's order is the day
    // `sequence`, never the insertion order.
    EventDay::factory()->forEvent($event, 2)->create(['date' => '2027-06-16', 'label' => 'Samstag', 'day_ends_at' => '03:00']);
    EventDay::factory()->forEvent($event, 1)->create(['date' => '2027-06-15', 'label' => 'Freitag']);

    Event::fake([EventDaysChanged::class]);

    $this->artisan('event:replay-details')->assertSuccessful();

    Event::assertDispatchedTimes(EventDaysChanged::class, 1);
    Event::assertDispatched(
        EventDaysChanged::class,
        fn (EventDaysChanged $e): bool => $e->eventId === $event->id
            && $e->brandId === $event->brand_id
            && count($e->days) === 2
            && $e->days[0]['date'] === '2027-06-15'
            && $e->days[0]['sequence'] === 1
            && $e->days[0]['label'] === 'Freitag'
            && $e->days[0]['day_ends_at'] === null
            && $e->days[1]['date'] === '2027-06-16'
            && $e->days[1]['sequence'] === 2
            && $e->days[1]['day_ends_at'] === '03:00',
    );
});

it('re-emits an empty day set for an event that has none, because that is its current set', function (): void {
    EventModel::factory()->create();

    Event::fake([EventDaysChanged::class]);

    $this->artisan('event:replay-details')->assertSuccessful();

    Event::assertDispatched(EventDaysChanged::class, fn (EventDaysChanged $e): bool => $e->days === []);
});

it('does not re-emit VenueAssigned for a venue that is not primary', function (): void {
    $this->withBrand();
    $event = EventModel::factory()->create();
    $secondary = Venue::factory()->create();
    app(AssignVenue::class)->handle($event, $secondary, isPrimary: false);

    Event::fake([EventRescheduled::class, EventDetailsUpdated::class, VenueAssigned::class]);

    $this->artisan('event:replay-details', ['--event' => $event->id])->assertSuccessful();

    Event::assertNotDispatched(VenueAssigned::class);
});

/**
 * `--with-state` (0.9.0): the three events above name and schedule a row,
 * but say nothing about the event's lifecycle state. Agenda's
 * `agenda_event_summaries` is the first read model whose whole public
 * surface hangs off that state, and an event that predates the module has
 * emitted no lifecycle event since — so without this, a brand activating
 * Agenda gets a programme editor that works and a public page that 404s
 * until the event's next transition, which may be never.
 *
 * The seven lifecycle classes are all faked together in these tests so that
 * "only one was emitted" is an assertion and not an assumption. The helper's
 * name is prefixed because Pest helper names are process-global across every
 * module's test files (`.ai/rules/tests.md`).
 *
 * @return list<class-string>
 */
function eventReplayLifecycleEvents(): array
{
    return [
        EventAnnounced::class,
        EventPublished::class,
        EventUnpublished::class,
        EventStarted::class,
        EventConcluded::class,
        EventCancelled::class,
        EventArchived::class,
    ];
}

it('says nothing about the lifecycle without the flag', function (): void {
    EventModel::factory()->create(['state' => EventLifecycleState::Published, 'published_at' => '2027-01-02 09:00:00']);

    Event::fake(eventReplayLifecycleEvents());

    $this->artisan('event:replay-details')->assertSuccessful();

    Event::assertNotDispatched(EventPublished::class);
});

it('re-emits the one lifecycle event the event is in now, with its stored timestamp', function (): void {
    $event = EventModel::factory()->create([
        'state' => EventLifecycleState::Concluded,
        'published_at' => '2027-01-02 09:00:00',
        'concluded_at' => '2027-06-17 22:00:00',
    ]);

    Event::fake(eventReplayLifecycleEvents());

    $this->artisan('event:replay-details', ['--with-state' => true])
        ->expectsOutputToContain('Re-emitted the lifecycle state of 1 event(s)')
        ->assertSuccessful();

    Event::assertDispatchedTimes(EventConcluded::class, 1);
    Event::assertDispatched(
        EventConcluded::class,
        fn (EventConcluded $e): bool => $e->eventId === $event->id
            && $e->brandId === $event->brand_id
            && $e->concludedAt === $event->concluded_at->toIso8601String(),
    );

    // Only the current state, never the path that led to it: replaying
    // EventPublished for a concluded event would tell Registration the
    // event went public today.
    Event::assertNotDispatched(EventPublished::class);
});

it('re-emits a cancellation with the reason the row still carries', function (): void {
    $event = EventModel::factory()->create([
        'state' => EventLifecycleState::Cancelled,
        'reason_code' => 'venue_lost',
        'cancelled_at' => '2027-03-01 08:30:00',
    ]);

    Event::fake(eventReplayLifecycleEvents());

    $this->artisan('event:replay-details', ['--with-state' => true])->assertSuccessful();

    Event::assertDispatched(
        EventCancelled::class,
        fn (EventCancelled $e): bool => $e->eventId === $event->id
            && $e->reasonCode === 'venue_lost'
            && $e->cancelledAt === $event->cancelled_at->toIso8601String(),
    );
});

it('emits nothing for a draft, which has no lifecycle event to re-emit', function (): void {
    EventModel::factory()->create(['state' => EventLifecycleState::Draft]);

    Event::fake(eventReplayLifecycleEvents());

    $this->artisan('event:replay-details', ['--with-state' => true])
        ->expectsOutputToContain('Re-emitted the lifecycle state of 0 event(s)')
        ->assertSuccessful();

    foreach (eventReplayLifecycleEvents() as $class) {
        Event::assertNotDispatched($class);
    }
});

it('never re-emits EventUnpublished, because Announced is what an unpublished event is', function (): void {
    EventModel::factory()->create(['state' => EventLifecycleState::Announced]);

    Event::fake(eventReplayLifecycleEvents());

    $this->artisan('event:replay-details', ['--with-state' => true])->assertSuccessful();

    Event::assertDispatchedTimes(EventAnnounced::class, 1);
    Event::assertNotDispatched(EventUnpublished::class);
});

it('fills in a timestamp for the two states that store none', function (): void {
    $event = EventModel::factory()->create(['state' => EventLifecycleState::Running, 'published_at' => '2027-01-02 09:00:00']);

    Event::fake(eventReplayLifecycleEvents());

    $this->artisan('event:replay-details', ['--with-state' => true])->assertSuccessful();

    Event::assertDispatched(
        EventStarted::class,
        fn (EventStarted $e): bool => $e->eventId === $event->id
            && $e->startedAt === $event->updated_at->toIso8601String(),
    );
});

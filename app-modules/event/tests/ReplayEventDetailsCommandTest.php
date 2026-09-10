<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Models\Event as EventModel;

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

it('emits the schedule before the details, so a window exists before it is named', function (): void {
    EventModel::factory()->create();

    $order = [];
    Event::listen(EventRescheduled::class, function () use (&$order): void {
        $order[] = 'rescheduled';
    });
    Event::listen(EventDetailsUpdated::class, function () use (&$order): void {
        $order[] = 'details';
    });

    $this->artisan('event:replay-details')->assertSuccessful();

    expect($order)->toBe(['rescheduled', 'details']);
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

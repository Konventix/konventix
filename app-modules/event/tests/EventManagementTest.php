<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Validation\ValidationException;
use Konventix\Event\Actions\AnnounceEvent;
use Konventix\Event\Actions\AssignVenue;
use Konventix\Event\Actions\CreateEvent;
use Konventix\Event\Actions\PublishEvent;
use Konventix\Event\Actions\StartEvent;
use Konventix\Event\Actions\SyncEventDays;
use Konventix\Event\Actions\UnassignVenue;
use Konventix\Event\Data\EventData;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Enums\EventVisibility;
use Konventix\Event\Enums\ReentryPolicy;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDaysChanged;
use Konventix\Event\Events\EventStarted;
use Konventix\Event\Events\VenueAssigned;
use Konventix\Event\Exceptions\IllegalEventStateTransitionException;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\Venue;

function eventFormData(string $slug = 'sxlan-2027'): EventData
{
    return new EventData(
        name: 'SXLan 2027',
        slug: $slug,
        description: 'The LAN.',
        startsAt: '2027-05-14 18:00:00',
        endsAt: '2027-05-16 14:00:00',
        timezone: 'Europe/Berlin',
        visibility: EventVisibility::Public,
        reentryPolicy: ReentryPolicy::Unlimited,
        predecessorEventId: null,
    );
}

it('creates an event with days under an explicit brand', function (): void {
    $brand = $this->withBrand();

    EventBus::fake([EventCreated::class, EventDaysChanged::class]);

    $event = app(CreateEvent::class)->handle($brand->getKey(), eventFormData(), [
        ['date' => '2027-05-15', 'label' => 'Main day'],
        ['date' => '2027-05-14', 'label' => null],
    ]);

    expect($event->brand_id)->toBe($brand->getKey())
        ->and($event->reentry_policy)->toBe(ReentryPolicy::Unlimited)
        ->and($event->days)->toHaveCount(2)
        // Sequence derives from date order, not input order.
        ->and($event->days[0]->date->toDateString())->toBe('2027-05-14')
        ->and($event->days[0]->sequence)->toBe(1)
        ->and($event->days[1]->label)->toBe('Main day');

    EventBus::assertDispatched(EventCreated::class, fn (EventCreated $e): bool => $e->slug === 'sxlan-2027');
    EventBus::assertDispatched(EventDaysChanged::class, fn (EventDaysChanged $e): bool => count($e->days) === 2
        && $e->days[0]['sequence'] === 1);
});

it('refuses a duplicate slug within the brand', function (): void {
    $brand = $this->withBrand();

    app(CreateEvent::class)->handle($brand->getKey(), eventFormData());
    app(CreateEvent::class)->handle($brand->getKey(), eventFormData());
})->throws(ValidationException::class);

it('keeps day ULIDs stable across a re-sync and emits the full new set', function (): void {
    $brand = $this->withBrand();

    $event = app(CreateEvent::class)->handle($brand->getKey(), eventFormData(), [
        ['date' => '2027-05-14', 'label' => null],
        ['date' => '2027-05-15', 'label' => null],
    ]);

    $keptDayId = $event->days[0]->id;

    EventBus::fake([EventDaysChanged::class]);

    // Drop the 15th, keep the 14th (relabelled), add the 16th.
    $event = app(SyncEventDays::class)->handle($event, [
        ['date' => '2027-05-16', 'label' => null],
        ['date' => '2027-05-14', 'label' => 'Arrival'],
    ]);

    expect($event->days)->toHaveCount(2)
        // Admission references days by ULID — a kept date keeps its identity.
        ->and($event->days[0]->id)->toBe($keptDayId)
        ->and($event->days[0]->label)->toBe('Arrival')
        ->and($event->days[1]->date->toDateString())->toBe('2027-05-16');

    EventBus::assertDispatchedTimes(EventDaysChanged::class, 1);
});

it('assigns venues with a single primary and demotes on promotion', function (): void {
    $this->withBrand();

    $event = Event::factory()->create();
    $first = Venue::factory()->create();
    $second = Venue::factory()->create();

    EventBus::fake([VenueAssigned::class]);

    app(AssignVenue::class)->handle($event, $first, isPrimary: true);
    app(AssignVenue::class)->handle($event, $second, isPrimary: true);

    $event->refresh()->load('venues');

    expect($event->venues)->toHaveCount(2)
        ->and($event->primaryVenue()?->id)->toBe($second->id);

    EventBus::assertDispatched(VenueAssigned::class, fn (VenueAssigned $e): bool => $e->venueId === $second->id && $e->isPrimary);

    app(UnassignVenue::class)->handle($event, $second);

    expect($event->refresh()->venues)->toHaveCount(1)
        ->and($event->primaryVenue())->toBeNull();
});

it('walks Draft → Announced → Published → Running through the actions', function (): void {
    $this->withBrand();

    $event = Event::factory()->create();

    EventBus::fake([EventStarted::class]);

    $event = app(AnnounceEvent::class)->handle($event);
    expect($event->state)->toBe(EventLifecycleState::Announced);

    $event = app(PublishEvent::class)->handle($event);
    expect($event->state)->toBe(EventLifecycleState::Published);

    $event = app(StartEvent::class)->handle($event);
    expect($event->state)->toBe(EventLifecycleState::Running);

    EventBus::assertDispatched(EventStarted::class);
});

it('refuses to start an event that is not published', function (): void {
    $this->withBrand();

    app(StartEvent::class)->handle(Event::factory()->create());
})->throws(IllegalEventStateTransitionException::class);

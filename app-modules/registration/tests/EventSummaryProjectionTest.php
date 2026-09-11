<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Events\VenueAssigned;
use Konventix\Event\Events\VenueUnassigned;
use Konventix\Event\Events\VenueUpdated;
use Konventix\Registration\Models\EventSummary;

/**
 * `registration_event_summaries`' schedule and primary-venue columns
 * (design doc §13, 2026-09-11 amendment "the calendar builder moves to
 * Platform, and two modules gain an event read model"): the confirmation
 * mail's calendar attachment needs them, built through
 * `Konventix\Platform\Calendar\CalendarFile` without Registration ever
 * importing Event (ADR-0004).
 */
function registrationEventCreatedEvent(
    string $eventId,
    string $brandId,
    string $name = 'SXLan 2026',
    string $slug = 'sxlan-2026',
    string $startsAt = '2027-06-15 10:00:00',
    string $endsAt = '2027-06-17 18:00:00',
    string $timezone = 'Europe/Berlin',
): EventCreated {
    return new EventCreated(
        eventId: $eventId,
        brandId: $brandId,
        slug: $slug,
        name: $name,
        startsAt: $startsAt,
        endsAt: $endsAt,
        timezone: $timezone,
        predecessorEventId: null,
    );
}

it('projects the schedule from EventCreated alongside the name and slug', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    event(registrationEventCreatedEvent($eventId, $brand->id));

    $summary = EventSummary::where('event_id', $eventId)->firstOrFail();

    expect($summary->starts_at->format('Y-m-d H:i:s'))->toBe('2027-06-15 10:00:00')
        ->and($summary->ends_at->format('Y-m-d H:i:s'))->toBe('2027-06-17 18:00:00')
        ->and($summary->timezone)->toBe('Europe/Berlin')
        ->and($summary->venue_label)->toBeNull();
});

it('a details update does not erase the times, and a reschedule does not erase the name', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    event(registrationEventCreatedEvent($eventId, $brand->id, name: 'Original Name'));

    event(new EventDetailsUpdated(eventId: $eventId, brandId: $brand->id, slug: 'renamed', name: 'Renamed'));

    $afterDetails = EventSummary::where('event_id', $eventId)->firstOrFail();
    expect($afterDetails->name)->toBe('Renamed')
        ->and($afterDetails->starts_at->format('Y-m-d H:i:s'))->toBe('2027-06-15 10:00:00');

    event(new EventRescheduled(
        eventId: $eventId,
        startsAt: '2027-07-01 09:00:00',
        endsAt: '2027-07-03 20:00:00',
        timezone: 'Europe/Berlin',
        brandId: $brand->id,
    ));

    $afterReschedule = EventSummary::where('event_id', $eventId)->firstOrFail();
    expect($afterReschedule->name)->toBe('Renamed')
        ->and($afterReschedule->starts_at->format('Y-m-d H:i:s'))->toBe('2027-07-01 09:00:00');
});

it('a reschedule for an event with no summary row yet is a safe no-op', function (): void {
    $brand = $this->withBrand();

    event(new EventRescheduled(
        eventId: (string) Str::ulid(),
        startsAt: '2027-07-01 09:00:00',
        endsAt: '2027-07-03 20:00:00',
        timezone: 'Europe/Berlin',
        brandId: $brand->id,
    ));

    expect(EventSummary::count())->toBe(0);
});

it('sets the venue label from the primary venue only, and clears it on unassign', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $venueId = (string) Str::ulid();
    event(registrationEventCreatedEvent($eventId, $brand->id));

    event(new VenueAssigned(eventId: $eventId, venueId: (string) Str::ulid(), brandId: $brand->id, name: 'Side Room', city: 'Hamburg', isPrimary: false));
    expect(EventSummary::where('event_id', $eventId)->firstOrFail()->venue_label)->toBeNull();

    event(new VenueAssigned(eventId: $eventId, venueId: $venueId, brandId: $brand->id, name: 'Congress Centrum', city: 'Hamburg', isPrimary: true));
    expect(EventSummary::where('event_id', $eventId)->firstOrFail()->venue_label)->toBe('Congress Centrum, Hamburg');

    event(new VenueUpdated(venueId: $venueId, brandId: $brand->id, name: 'Congress Centrum West', city: 'Hamburg', countryCode: 'DE'));
    expect(EventSummary::where('event_id', $eventId)->firstOrFail()->venue_label)->toBe('Congress Centrum West, Hamburg');

    event(new VenueUnassigned(eventId: $eventId, venueId: $venueId, brandId: $brand->id));
    $summary = EventSummary::where('event_id', $eventId)->firstOrFail();
    expect($summary->venue_label)->toBeNull()
        ->and($summary->venue_id)->toBeNull();
});

it('an event with no venue leaves the label null and a stray venue event for another event breaks nothing', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    event(registrationEventCreatedEvent($eventId, $brand->id));

    event(new VenueUpdated(venueId: (string) Str::ulid(), brandId: $brand->id, name: 'Unrelated', city: 'Berlin', countryCode: 'DE'));
    event(new VenueUnassigned(eventId: (string) Str::ulid(), venueId: (string) Str::ulid(), brandId: $brand->id));

    expect(EventSummary::where('event_id', $eventId)->firstOrFail()->venue_label)->toBeNull();
});

it('converges on replay: firing EventCreated twice for the same event leaves one row with the latest schedule', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    event(registrationEventCreatedEvent($eventId, $brand->id, startsAt: '2027-06-01 09:00:00', endsAt: '2027-06-01 18:00:00'));
    event(registrationEventCreatedEvent($eventId, $brand->id, startsAt: '2027-06-15 10:00:00', endsAt: '2027-06-17 18:00:00'));

    expect(EventSummary::where('event_id', $eventId)->count())->toBe(1);
    expect(EventSummary::where('event_id', $eventId)->firstOrFail()->starts_at->format('Y-m-d H:i:s'))->toBe('2027-06-15 10:00:00');
});

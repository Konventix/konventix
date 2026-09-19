<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Models\EventSummary;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Events\VenueAssigned;
use Konventix\Event\Events\VenueUnassigned;
use Konventix\Event\Events\VenueUpdated;

/**
 * Admission's read model of an event's name, schedule and primary venue
 * (design doc §13, 2026-09-11 amendment "Admission learns event facts"):
 * the ticket's event band (§4.2) needs a name, a local start and end, a
 * timezone and the venue's address, and Admission may not read Event's
 * models (ADR-0004) — so it keeps its own copy, the way Resources keeps
 * `resource_event_windows`.
 */
function admissionEventCreatedEvent(
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

it('projects EventCreated into the event summary read model', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    event(admissionEventCreatedEvent($eventId, $brand->id));

    $summary = EventSummary::where('event_id', $eventId)->firstOrFail();

    expect($summary->brand_id)->toBe($brand->id)
        ->and($summary->name)->toBe('SXLan 2026')
        ->and($summary->slug)->toBe('sxlan-2026')
        ->and($summary->starts_at->format('Y-m-d H:i:s'))->toBe('2027-06-15 10:00:00')
        ->and($summary->ends_at->format('Y-m-d H:i:s'))->toBe('2027-06-17 18:00:00')
        ->and($summary->timezone)->toBe('Europe/Berlin')
        ->and($summary->venue_label)->toBeNull();
});

it('converges on replay: firing EventCreated twice for the same event leaves one row with the latest values', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    event(admissionEventCreatedEvent($eventId, $brand->id, name: 'Working Title'));
    event(admissionEventCreatedEvent($eventId, $brand->id, name: 'SXLan 2026 — Final'));

    expect(EventSummary::where('event_id', $eventId)->count())->toBe(1);

    $summary = EventSummary::where('event_id', $eventId)->firstOrFail();
    expect($summary->name)->toBe('SXLan 2026 — Final');
});

it('a details update does not erase the times, and a reschedule does not erase the name', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    event(admissionEventCreatedEvent($eventId, $brand->id, name: 'Original Name', startsAt: '2027-06-15 10:00:00', endsAt: '2027-06-17 18:00:00'));

    event(new EventDetailsUpdated(
        eventId: $eventId,
        brandId: $brand->id,
        slug: 'renamed-slug',
        name: 'Renamed Event',
    ));

    $afterDetails = EventSummary::where('event_id', $eventId)->firstOrFail();

    expect($afterDetails->name)->toBe('Renamed Event')
        ->and($afterDetails->slug)->toBe('renamed-slug')
        ->and($afterDetails->starts_at->format('Y-m-d H:i:s'))->toBe('2027-06-15 10:00:00')
        ->and($afterDetails->ends_at->format('Y-m-d H:i:s'))->toBe('2027-06-17 18:00:00');

    event(new EventRescheduled(
        eventId: $eventId,
        startsAt: '2027-07-01 09:00:00',
        endsAt: '2027-07-03 20:00:00',
        timezone: 'Europe/Berlin',
        brandId: $brand->id,
    ));

    $afterReschedule = EventSummary::where('event_id', $eventId)->firstOrFail();

    expect($afterReschedule->name)->toBe('Renamed Event')
        ->and($afterReschedule->starts_at->format('Y-m-d H:i:s'))->toBe('2027-07-01 09:00:00')
        ->and($afterReschedule->ends_at->format('Y-m-d H:i:s'))->toBe('2027-07-03 20:00:00');
});

it('creates the row from a reschedule that names its brand, for an event that predates the projection', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    // Simulates event:replay-details' backfill order for an event this
    // table never had a row for: the schedule arrives before the details.
    event(new EventRescheduled(
        eventId: $eventId,
        startsAt: '2027-07-01 09:00:00',
        endsAt: '2027-07-03 20:00:00',
        timezone: 'Europe/Berlin',
        brandId: $brand->id,
    ));

    $summary = EventSummary::where('event_id', $eventId)->firstOrFail();

    expect($summary->starts_at->format('Y-m-d H:i:s'))->toBe('2027-07-01 09:00:00')
        ->and($summary->name)->toBeNull();

    event(new EventDetailsUpdated(eventId: $eventId, brandId: $brand->id, slug: 'backfilled', name: 'Backfilled Event'));

    expect($summary->fresh()->name)->toBe('Backfilled Event');
});

it('sets the venue label from the primary venue and ignores a non-primary one', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    event(admissionEventCreatedEvent($eventId, $brand->id));

    event(new VenueAssigned(
        eventId: $eventId,
        venueId: (string) Str::ulid(),
        brandId: $brand->id,
        name: 'Side Room',
        city: 'Hamburg',
        isPrimary: false,
    ));

    expect(EventSummary::where('event_id', $eventId)->firstOrFail()->venue_label)->toBeNull();

    $primaryVenueId = (string) Str::ulid();
    event(new VenueAssigned(
        eventId: $eventId,
        venueId: $primaryVenueId,
        brandId: $brand->id,
        name: 'Congress Centrum',
        city: 'Hamburg',
        isPrimary: true,
    ));

    $summary = EventSummary::where('event_id', $eventId)->firstOrFail();

    expect($summary->venue_label)->toBe('Congress Centrum, Hamburg')
        ->and($summary->venue_id)->toBe($primaryVenueId);
});

it('renames the venue label on VenueUpdated and clears it on VenueUnassigned', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $venueId = (string) Str::ulid();
    event(admissionEventCreatedEvent($eventId, $brand->id));
    event(new VenueAssigned(eventId: $eventId, venueId: $venueId, brandId: $brand->id, name: 'Congress Centrum', city: 'Hamburg', isPrimary: true));

    event(new VenueUpdated(venueId: $venueId, brandId: $brand->id, name: 'Congress Centrum West', city: 'Hamburg', countryCode: 'DE'));

    expect(EventSummary::where('event_id', $eventId)->firstOrFail()->venue_label)->toBe('Congress Centrum West, Hamburg');

    event(new VenueUnassigned(eventId: $eventId, venueId: $venueId, brandId: $brand->id));

    $summary = EventSummary::where('event_id', $eventId)->firstOrFail();

    expect($summary->venue_label)->toBeNull()
        ->and($summary->venue_id)->toBeNull();
});

it('an event with no venue leaves the label null, and a stray venue event for another event breaks nothing', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    event(admissionEventCreatedEvent($eventId, $brand->id));

    event(new VenueUpdated(venueId: (string) Str::ulid(), brandId: $brand->id, name: 'Unrelated Hall', city: 'Berlin', countryCode: 'DE'));
    event(new VenueUnassigned(eventId: (string) Str::ulid(), venueId: (string) Str::ulid(), brandId: $brand->id));

    expect(EventSummary::where('event_id', $eventId)->firstOrFail()->venue_label)->toBeNull();
});

// The banner (#292): carried by both events, and unlike the other columns
// null is a fact — the picture was removed — so a details update clears it.
it('projects the banner from EventCreated, patches it on EventDetailsUpdated and clears it on null', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $first = (string) Str::ulid();
    $second = (string) Str::ulid();

    $created = admissionEventCreatedEvent($eventId, $brand->id);
    event(new EventCreated(
        eventId: $created->eventId,
        brandId: $created->brandId,
        slug: $created->slug,
        name: $created->name,
        startsAt: $created->startsAt,
        endsAt: $created->endsAt,
        timezone: $created->timezone,
        predecessorEventId: null,
        bannerMediaId: $first,
    ));

    expect(EventSummary::where('event_id', $eventId)->firstOrFail()->banner_media_id)->toBe($first);

    event(new EventDetailsUpdated(
        eventId: $eventId,
        brandId: $brand->id,
        slug: 'sxlan-2026',
        name: 'SXLan 2026',
        bannerMediaId: $second,
    ));

    $summary = EventSummary::where('event_id', $eventId)->firstOrFail();

    expect($summary->banner_media_id)->toBe($second)
        // A details update still leaves the schedule alone.
        ->and($summary->starts_at->format('Y-m-d H:i:s'))->toBe('2027-06-15 10:00:00');

    event(new EventDetailsUpdated(
        eventId: $eventId,
        brandId: $brand->id,
        slug: 'sxlan-2026',
        name: 'SXLan 2026',
        bannerMediaId: null,
    ));

    expect(EventSummary::where('event_id', $eventId)->firstOrFail()->banner_media_id)->toBeNull();
});

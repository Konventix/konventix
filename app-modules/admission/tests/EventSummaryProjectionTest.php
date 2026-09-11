<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Models\EventSummary;
use Konventix\Event\Events\EventCreated;

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

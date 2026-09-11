<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Models\Event as EventModel;
use Konventix\Resources\Models\ResourceEventWindow;

/**
 * Resources' read model of when an event runs (ADR-0004: this module may not
 * read Event's models). Event times are wall-clock plus an IANA zone
 * (ADR-0027); the window stores the UTC instants the booking constraint
 * compares, so the conversion has to happen here, with the payload's zone —
 * before S0 the strings were parsed as UTC and every Berlin event was two
 * hours early.
 */
it('stores a Europe/Berlin payload as the UTC instant it names', function (): void {
    $brand = $this->withBrand();

    $window = seatingProjectWindow(
        eventId: (string) Str::ulid(),
        brandId: $brand->id,
        startsAt: '2027-06-15 10:00:00',
        endsAt: '2027-06-17 18:00:00',
        timezone: 'Europe/Berlin',
    );

    // CEST is UTC+2 in June.
    expect($window->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2027-06-15 08:00:00')
        ->and($window->ends_at->utc()->format('Y-m-d H:i:s'))->toBe('2027-06-17 16:00:00');
});

it('projects the name and the slug from EventCreated', function (): void {
    $brand = $this->withBrand();

    $window = seatingProjectWindow((string) Str::ulid(), $brand->id, name: 'SXLan 2026', slug: 'sxlan-2026');

    expect($window->name)->toBe('SXLan 2026')
        ->and($window->slug)->toBe('sxlan-2026');
});

it('upserts by brand and event on a reschedule that carries no brand', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    seatingProjectWindow($eventId, $brand->id);

    event(new EventRescheduled(
        eventId: $eventId,
        startsAt: '2027-07-01 09:00:00',
        endsAt: '2027-07-03 20:00:00',
        timezone: 'Europe/Berlin',
    ));

    $windows = ResourceEventWindow::withoutBrandScope()->where('event_id', $eventId)->get();

    expect($windows)->toHaveCount(1)
        ->and($windows->first()->brand_id)->toBe($brand->id)
        ->and($windows->first()->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2027-07-01 07:00:00')
        ->and($windows->first()->name)->not->toBeNull();
});

it('creates the window from a reschedule that names its brand', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    event(new EventRescheduled(
        eventId: $eventId,
        startsAt: '2027-07-01 09:00:00',
        endsAt: '2027-07-03 20:00:00',
        timezone: 'Europe/Berlin',
        brandId: $brand->id,
    ));

    $window = ResourceEventWindow::withoutBrandScope()->where('event_id', $eventId)->first();

    expect($window)->not->toBeNull()
        ->and($window->brand_id)->toBe($brand->id)
        ->and($window->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2027-07-01 07:00:00');
});

it('drops a reschedule for an event it has no window for and no brand to file it under', function (): void {
    $this->withBrand();

    event(new EventRescheduled(
        eventId: (string) Str::ulid(),
        startsAt: '2027-07-01 09:00:00',
        endsAt: '2027-07-03 20:00:00',
        timezone: 'Europe/Berlin',
    ));

    expect(ResourceEventWindow::withoutBrandScope()->count())->toBe(0);
});

it('renames the window on EventDetailsUpdated and ignores details for an unknown event', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    seatingProjectWindow($eventId, $brand->id, name: 'Old name', slug: 'old-name');

    event(new EventDetailsUpdated(eventId: $eventId, brandId: $brand->id, slug: 'new-name', name: 'New name'));
    event(new EventDetailsUpdated(eventId: (string) Str::ulid(), brandId: $brand->id, slug: 'ghost', name: 'Ghost'));

    $window = ResourceEventWindow::withoutBrandScope()->where('event_id', $eventId)->firstOrFail();

    expect($window->name)->toBe('New name')
        ->and($window->slug)->toBe('new-name')
        ->and(ResourceEventWindow::withoutBrandScope()->count())->toBe(1);
});

it('is backfilled by event:replay-details for an event that predates the projection', function (): void {
    $brand = $this->withBrand();

    // The factory writes the row without emitting EventCreated — exactly
    // the state of every event that existed before Resources was on.
    $event = EventModel::factory()->create([
        'name' => 'Backfilled event',
        'slug' => 'backfilled-event',
        'starts_at' => '2027-06-15 10:00:00',
        'ends_at' => '2027-06-17 18:00:00',
        'timezone' => 'Europe/Berlin',
    ]);

    expect(ResourceEventWindow::withoutBrandScope()->where('event_id', $event->id)->exists())->toBeFalse();

    $this->artisan('event:replay-details', ['--event' => $event->id])->assertSuccessful();

    $window = ResourceEventWindow::withoutBrandScope()->where('event_id', $event->id)->firstOrFail();

    expect($window->brand_id)->toBe($brand->id)
        ->and($window->name)->toBe('Backfilled event')
        ->and($window->slug)->toBe('backfilled-event')
        ->and($window->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2027-06-15 08:00:00');
});

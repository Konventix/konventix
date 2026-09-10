<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Event\Events\EventCreated;
use Konventix\Resources\Actions\AssignPlacesToRegistration;
use Konventix\Resources\Actions\ConfirmResourceBooking;
use Konventix\Resources\Actions\HoldResourceBooking;
use Konventix\Resources\Actions\ReleaseResourceBooking;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\SeatingMode;
use Konventix\Resources\Events\ResourceBookingRefused;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceClaim;
use Konventix\Resources\Models\ResourceEventWindow;
use Konventix\Resources\Models\ResourceType;

/**
 * Resources' own numbers (ADR-0059, design §9.6): booking outcomes from the
 * module's events, plan-save outcomes from Task 44, claims and the sweep
 * heartbeat as scrape-time gauges. Brands appear as slugs; no label ever
 * carries a registration, a user or a place. Helper names prefixed
 * `seating` — Pest helpers are process-global (.ai/rules/tests.md).
 *
 * The unplaced-claims gauge carries the event as well as the brand
 * (2026-09-10 batch amendment, ahead of the §12b quota decision): "eleven
 * claims with no seat" is meaningless to an operator without knowing which
 * event is short, and an unplaced claim after the picker opens means
 * someone paid and cannot sit down — a business signal, not a queue depth.
 * Resources projects its own read of the event's slug into
 * `resource_event_windows` already (ADR-0004), so the gauge joins that
 * instead of ever seeing an Event model.
 */
function seatingMetricsEventWindow(string $eventId, string $brandId): ResourceEventWindow
{
    event(new EventCreated(
        eventId: $eventId,
        brandId: $brandId,
        slug: 'e-'.Str::lower(Str::random(6)),
        name: 'Metrics event',
        startsAt: now()->startOfHour()->addDay()->format('Y-m-d H:i:s'),
        endsAt: now()->startOfHour()->addDays(3)->format('Y-m-d H:i:s'),
        timezone: 'Europe/Berlin',
        predecessorEventId: null,
    ));

    return ResourceEventWindow::withoutBrandScope()->where('event_id', $eventId)->firstOrFail();
}

it('counts held, confirmed, released and expired bookings per brand slug', function (): void {
    $brand = $this->withBrand();
    $resource = Resource::factory()->ofType(ResourceType::factory()->create())->create();

    $held = app(HoldResourceBooking::class)->handle(
        resource: $resource,
        holderType: ResourceBooking::HOLDER_REGISTRATION,
        holderId: (string) Str::ulid(),
        startsAt: now()->startOfHour()->addDay(),
        endsAt: now()->startOfHour()->addDays(2),
    );
    $confirmed = app(ConfirmResourceBooking::class)->handle($held);
    app(ReleaseResourceBooking::class)->handle($confirmed, BookingState::Released, 'repicked');

    $clocked = app(HoldResourceBooking::class)->handle(
        resource: $resource,
        holderType: ResourceBooking::HOLDER_REGISTRATION,
        holderId: (string) Str::ulid(),
        startsAt: now()->startOfHour()->addDays(3),
        endsAt: now()->startOfHour()->addDays(4),
    );
    app(ReleaseResourceBooking::class)->handle($clocked, BookingState::Expired, 'expired');

    expect(seatingMetricsRendered())
        ->toContain('konventix_resource_bookings_total{brand="'.$brand->slug.'",outcome="held"} 2')
        ->toContain('konventix_resource_bookings_total{brand="'.$brand->slug.'",outcome="confirmed"} 1')
        ->toContain('konventix_resource_bookings_total{brand="'.$brand->slug.'",outcome="released"} 1')
        ->toContain('konventix_resource_bookings_total{brand="'.$brand->slug.'",outcome="expired"} 1');
});

it('counts a refused booking from the refusal event', function (): void {
    $brand = $this->withBrand();

    event(new ResourceBookingRefused(
        brandId: $brand->id,
        eventId: (string) Str::ulid(),
        registrationId: (string) Str::ulid(),
        reason: 'unavailable',
    ));

    expect(seatingMetricsRendered())
        ->toContain('konventix_resource_bookings_total{brand="'.$brand->slug.'",outcome="refused"} 1');
});

it('records a refused pick when the exclusion constraint stops the assignment', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $window = seatingMetricsEventWindow($eventId, $brand->id);

    $type = ResourceType::factory()->forEvent($eventId)->create();
    $plan = Plan::factory()->create(['event_id' => $eventId, 'published_at' => now()]);
    $place = Resource::factory()->ofType($type)->create(['plan_id' => $plan->id, 'ref' => 'A-01', 'label' => 'A-01', 'is_published' => true]);

    // Somebody else already holds the place for the whole event window.
    ResourceBooking::factory()->create([
        'resource_id' => $place->id,
        'starts_at' => $window->starts_at,
        'ends_at' => $window->ends_at,
    ]);

    $registrantUserId = (string) Str::ulid();
    $claim = ResourceClaim::factory()->create([
        'event_id' => $eventId,
        'registration_id' => (string) Str::ulid(),
        'registrant_user_id' => $registrantUserId,
        'resource_type_ids' => [$type->id],
        'seating_mode' => SeatingMode::Pick,
        'quantity' => 1,
        'state' => ClaimState::Unplaced,
    ]);

    // The constraint fires inside the Action's nested transaction (a
    // savepoint), so the assertions after it can still query the database.
    expect(fn () => app(AssignPlacesToRegistration::class)->handle($claim, [$place->id], 'registrant', $registrantUserId))
        ->toThrow(ValidationException::class);

    expect(seatingMetricsRendered())
        ->toContain('konventix_resource_bookings_total{brand="'.$brand->slug.'",outcome="refused"} 1')
        ->and(ResourceBooking::query()->where('claim_id', $claim->id)->whereIn('state', BookingState::occupyingValues())->count())->toBe(0);
});

it('labels an unknown brand id as unknown rather than failing the event', function (): void {
    event(new ResourceBookingRefused(
        brandId: '01NOSUCHBRAND',
        eventId: (string) Str::ulid(),
        registrationId: (string) Str::ulid(),
        reason: 'deadlock',
    ));

    expect(seatingMetricsRendered())->toContain('konventix_resource_bookings_total{brand="unknown",outcome="refused"} 1');
});

it('reports unplaced seat claims per brand and event at scrape time', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $window = seatingMetricsEventWindow($eventId, $brand->id);
    $typeId = ResourceType::factory()->forEvent($eventId)->create()->id;

    foreach ([ClaimState::Unplaced, ClaimState::Unplaced, ClaimState::Placed, ClaimState::Released] as $state) {
        ResourceClaim::factory()->create([
            'event_id' => $eventId,
            'registration_id' => (string) Str::ulid(),
            'registrant_user_id' => (string) Str::ulid(),
            'resource_type_ids' => [$typeId],
            'seating_mode' => SeatingMode::Pick,
            'quantity' => 1,
            'state' => $state,
        ]);
    }

    expect(seatingMetricsScrape())->toContain('konventix_resource_seat_claims_unplaced{brand="'.$brand->slug.'",event="'.$window->slug.'"} 2');
});

it('labels an unplaced claim for an unprojected event as unknown', function (): void {
    $brand = $this->withBrand();
    // No EventCreated fired for this event id: ResourceEventWindow has no row for it.
    $eventId = (string) Str::ulid();
    $typeId = ResourceType::factory()->forEvent($eventId)->create()->id;

    ResourceClaim::factory()->create([
        'event_id' => $eventId,
        'registration_id' => (string) Str::ulid(),
        'registrant_user_id' => (string) Str::ulid(),
        'resource_type_ids' => [$typeId],
        'seating_mode' => SeatingMode::Pick,
        'quantity' => 1,
        'state' => ClaimState::Unplaced,
    ]);

    expect(seatingMetricsScrape())->toContain('konventix_resource_seat_claims_unplaced{brand="'.$brand->slug.'",event="unknown"} 1');
});

it('emits no sweep heartbeat before the sweep ever ran', function (): void {
    $this->withBrand();

    expect(seatingMetricsScrape())->not->toMatch('/^konventix_resource_hold_sweep_last_run_timestamp_seconds \d/m');
});

it('reports when the hold sweep last finished', function (): void {
    $this->withBrand();
    $this->travelTo(now()->startOfMinute());

    Artisan::call('resources:expire-holds');

    expect(seatingMetricsScrape())
        ->toContain('konventix_resource_hold_sweep_last_run_timestamp_seconds '.now()->getTimestamp());
});

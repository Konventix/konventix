<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceEventWindow;
use Konventix\Resources\Models\ResourceType;

/**
 * Materializing `resource_claim` grants into bookings.
 *
 * Before this slice these grants were silently dropped — Admission skips any
 * grant that is not an admission, and nothing else listened. Helper names
 * prefixed: Pest helpers are process-global (.ai/rules/tests.md).
 */
function claimTestWindow(string $eventId, string $brandId): ResourceEventWindow
{
    event(new EventCreated(
        eventId: $eventId,
        brandId: $brandId,
        slug: 'e-'.Str::lower(Str::random(6)),
        name: 'Test event',
        startsAt: now()->startOfHour()->addDay()->format('Y-m-d H:i:s'),
        endsAt: now()->startOfHour()->addDays(3)->format('Y-m-d H:i:s'),
        timezone: 'Europe/Berlin',
        predecessorEventId: null,
    ));

    return ResourceEventWindow::withoutBrandScope()->where('event_id', $eventId)->firstOrFail();
}

function claimTestConfirm(string $eventId, string $brandId, array $grants, ?string $registrationId = null): string
{
    $registrationId ??= (string) Str::ulid();

    event(new RegistrationConfirmed(
        registrationId: $registrationId,
        registrationTypeId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        confirmedAt: now()->toIso8601String(),
        grants: $grants,
    ));

    return $registrationId;
}

function claimTestGrant(string $resourceTypeId, int $quantity = 1): array
{
    return ['kind' => 'resource_claim', 'role' => 'booth', 'quantity' => $quantity, 'resource_type_id' => $resourceTypeId];
}

it('projects the event window from Event contract events', function (): void {
    // Resources may not read Event's models (ADR-0004), so a booking's
    // interval has to come from a projection.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    $window = claimTestWindow($eventId, $brand->id);

    expect($window->starts_at->toDateTimeString())->toBe(now()->startOfHour()->addDay()->toDateTimeString());

    event(new EventRescheduled(
        eventId: $eventId,
        startsAt: now()->startOfHour()->addDays(5)->format('Y-m-d H:i:s'),
        endsAt: now()->startOfHour()->addDays(7)->format('Y-m-d H:i:s'),
        timezone: 'Europe/Berlin',
    ));

    expect($window->fresh()->starts_at->toDateTimeString())
        ->toBe(now()->startOfHour()->addDays(5)->toDateTimeString());
});

it('books a resource for a confirmed registration claim', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');

    $eventId = (string) Str::ulid();
    $window = claimTestWindow($eventId, $brand->id);

    $type = ResourceType::factory()->forEvent($eventId)->create();
    $resource = Resource::factory()->ofType($type)->create();

    $registrationId = claimTestConfirm($eventId, $brand->id, [claimTestGrant($type->id)]);

    $booking = ResourceBooking::query()->where('registration_id', $registrationId)->firstOrFail();

    expect($booking->resource_id)->toBe($resource->id)
        // A claim from a confirmed registration is not provisional — the
        // commercial act already happened.
        ->and($booking->state)->toBe(BookingState::Confirmed)
        ->and($booking->starts_at->equalTo($window->starts_at))->toBeTrue();
});

it('ignores admission grants, which are Admission business', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');

    $eventId = (string) Str::ulid();
    claimTestWindow($eventId, $brand->id);

    claimTestConfirm($eventId, $brand->id, [
        ['kind' => 'admission', 'role' => 'visitor', 'quantity' => 2, 'resource_type_id' => null],
    ]);

    expect(ResourceBooking::query()->count())->toBe(0);
});

it('leaves claims unfulfilled rather than failing when the module is off', function (): void {
    // SPEC: "a RegistrationType that grants resource claims still registers
    // people correctly — the claims simply stay unfulfilled".
    $brand = $this->withBrand();
    // Deliberately not activated — Resources is off for this brand.

    $eventId = (string) Str::ulid();
    claimTestWindow($eventId, $brand->id);
    $type = ResourceType::factory()->forEvent($eventId)->create();
    Resource::factory()->ofType($type)->create();

    claimTestConfirm($eventId, $brand->id, [claimTestGrant($type->id)]);

    expect(ResourceBooking::query()->count())->toBe(0);
});

it('leaves a claim unfulfilled when nothing is free rather than failing', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');

    $eventId = (string) Str::ulid();
    claimTestWindow($eventId, $brand->id);

    $type = ResourceType::factory()->forEvent($eventId)->create();
    Resource::factory()->ofType($type)->create();

    // First registration takes the only resource.
    claimTestConfirm($eventId, $brand->id, [claimTestGrant($type->id)]);
    // Second finds nothing — and must not blow up the confirmation.
    $second = claimTestConfirm($eventId, $brand->id, [claimTestGrant($type->id)]);

    expect(ResourceBooking::query()->count())->toBe(1)
        ->and(ResourceBooking::query()->where('registration_id', $second)->count())->toBe(0);
});

it('takes the next free resource when the first is taken', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');

    $eventId = (string) Str::ulid();
    claimTestWindow($eventId, $brand->id);

    $type = ResourceType::factory()->forEvent($eventId)->create();
    Resource::factory()->ofType($type)->create(['label' => 'A-01']);
    Resource::factory()->ofType($type)->create(['label' => 'A-02']);

    claimTestConfirm($eventId, $brand->id, [claimTestGrant($type->id)]);
    claimTestConfirm($eventId, $brand->id, [claimTestGrant($type->id)]);

    expect(ResourceBooking::query()->count())->toBe(2)
        ->and(ResourceBooking::query()->distinct()->pluck('resource_id')->count())->toBe(2);
});

it('is idempotent when a confirmation is replayed', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');

    $eventId = (string) Str::ulid();
    claimTestWindow($eventId, $brand->id);

    $type = ResourceType::factory()->forEvent($eventId)->create();
    Resource::factory()->ofType($type)->create();
    Resource::factory()->ofType($type)->create();

    $registrationId = (string) Str::ulid();
    claimTestConfirm($eventId, $brand->id, [claimTestGrant($type->id)], $registrationId);
    claimTestConfirm($eventId, $brand->id, [claimTestGrant($type->id)], $registrationId);

    expect(ResourceBooking::query()->where('registration_id', $registrationId)->count())->toBe(1);
});

it('leaves claims unfulfilled when no event window has been projected', function (): void {
    // Nothing to book an interval against; guessing one would be worse.
    $brand = $this->withBrand();
    $this->activateModule('resources');

    $eventId = (string) Str::ulid();
    $type = ResourceType::factory()->forEvent($eventId)->create();
    Resource::factory()->ofType($type)->create();

    claimTestConfirm($eventId, $brand->id, [claimTestGrant($type->id)]);

    expect(ResourceBooking::query()->count())->toBe(0);
});

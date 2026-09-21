<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Resources\Actions\AssignPlacesToRegistration;
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
        registrantUserId: (string) Str::ulid(),
        confirmedAt: now()->toIso8601String(),
        grants: $grants,
    ));

    return $registrationId;
}

function claimTestGrant(string $resourceTypeId, int $quantity = 1): array
{
    return ['kind' => 'resource_claim', 'role' => 'booth', 'quantity' => $quantity, 'resource_type_id' => $resourceTypeId, 'resource_type_ids' => null, 'seating_mode' => null];
}

it('projects the event window from Event contract events', function (): void {
    // Resources may not read Event's models (ADR-0004), so a booking's
    // interval has to come from a projection.
    //
    // Frozen: the fixture and the expectation each call `now()->startOfHour()`,
    // and a run that straddles the top of an hour (pipeline 1893, 22:00:10
    // UTC) sees two different hours.
    $this->freezeTime();
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    $window = claimTestWindow($eventId, $brand->id);

    expect($window->starts_at->utc()->toDateTimeString())
        ->toBe(Date::parse(now()->startOfHour()->addDay()->format('Y-m-d H:i:s'), 'Europe/Berlin')->utc()->toDateTimeString());

    event(new EventRescheduled(
        eventId: $eventId,
        startsAt: now()->startOfHour()->addDays(5)->format('Y-m-d H:i:s'),
        endsAt: now()->startOfHour()->addDays(7)->format('Y-m-d H:i:s'),
        timezone: 'Europe/Berlin',
    ));

    expect($window->fresh()->starts_at->utc()->toDateTimeString())
        ->toBe(Date::parse(now()->startOfHour()->addDays(5)->format('Y-m-d H:i:s'), 'Europe/Berlin')->utc()->toDateTimeString());
});

it('books a resource for a confirmed registration claim', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');

    $eventId = (string) Str::ulid();
    $window = claimTestWindow($eventId, $brand->id);

    $type = ResourceType::factory()->forEvent($eventId)->create();
    $resource = Resource::factory()->published()->ofType($type)->create();

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
        ['kind' => 'admission', 'role' => 'visitor', 'quantity' => 2, 'resource_type_id' => null, 'resource_type_ids' => null, 'seating_mode' => null],
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
    Resource::factory()->published()->ofType($type)->create();

    claimTestConfirm($eventId, $brand->id, [claimTestGrant($type->id)]);

    expect(ResourceBooking::query()->count())->toBe(0);
});

it('leaves a claim unfulfilled when nothing is free rather than failing', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');

    $eventId = (string) Str::ulid();
    claimTestWindow($eventId, $brand->id);

    $type = ResourceType::factory()->forEvent($eventId)->create();
    Resource::factory()->published()->ofType($type)->create();

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
    Resource::factory()->published()->ofType($type)->create(['label' => 'A-01']);
    Resource::factory()->published()->ofType($type)->create(['label' => 'A-02']);

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
    Resource::factory()->published()->ofType($type)->create();
    Resource::factory()->published()->ofType($type)->create();

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
    Resource::factory()->published()->ofType($type)->create();

    claimTestConfirm($eventId, $brand->id, [claimTestGrant($type->id)]);

    expect(ResourceBooking::query()->count())->toBe(0);
});

it('skips a blocked place when it fills a resource_claim grant', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');

    $eventId = (string) Str::ulid();
    seatingProjectWindow($eventId, $brand->id);
    $type = seatingUnitType($eventId);

    $blocked = seatingPublishedResource($type, 'A-01');
    $blocked->forceFill(['blocked' => true])->save();
    $free = seatingPublishedResource($type, 'A-02');

    seatingConfirmRegistration($eventId, $brand->id, [seatingClaimGrant($type->id)]);

    expect(ResourceBooking::withoutBrandScope()->where('resource_id', $blocked->id)->count())->toBe(0)
        ->and(ResourceBooking::withoutBrandScope()->where('resource_id', $free->id)->count())->toBe(1);
});

/**
 * Item 4 of the 2026-09-10 fix wave (#278): a seat booking (`claim_id` set)
 * shares this Action's idempotency key space — `(brand_id, registration_id,
 * amendment_id)` — with a `resource_claim` booking. Before the fix, a
 * confirmation that found no event window fulfilled nothing; the
 * registrant then picked a seat; a later replay of the same confirmation
 * saw the seat booking already in that key space and silently never
 * fulfilled the booth grant, even once the window existed.
 */
it('still fulfils a resource_claim grant on replay after a seat was picked in between', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');

    $eventId = (string) Str::ulid();
    // No window projected yet.
    $seatType = seatingCategory($eventId, 'seat');
    $boothType = seatingUnitType($eventId);
    // `AssignPlacesToRegistration` (the pick path) only ever accepts a
    // place on a *published plan* (design §7.3) — a plan-less resource
    // like `seatingPublishedResource()` builds is never eligible.
    ['places' => $seatPlaces] = seatingPublishedPlan($eventId, $seatType, 1);
    $seatPlace = $seatPlaces[0];
    $booth = seatingPublishedResource($boothType, 'Booth-01');

    $grants = [seatingClaimGrant($boothType->id), seatingGrantPayload([$seatType->id])];
    $registrationId = seatingConfirmRegistration($eventId, $brand->id, $grants);

    // The window was missing, so nothing was booked — the booth grant is
    // still open and the seating claim is unplaced.
    expect(ResourceBooking::query()->count())->toBe(0);

    seatingProjectWindow($eventId, $brand->id);
    $claim = seatingClaimFor($registrationId);
    app(AssignPlacesToRegistration::class)->handle($claim, [$seatPlace->id], AssignPlacesToRegistration::ACTOR_OPERATOR, null);

    // A replayed confirmation must still fulfil the booth grant now that
    // the window exists, even though a seat booking already occupies the
    // same (brand, registration, amendment) key space.
    seatingConfirmRegistration($eventId, $brand->id, $grants, $registrationId);

    expect(ResourceBooking::withoutBrandScope()->where('resource_id', $booth->id)->count())->toBe(1);
});

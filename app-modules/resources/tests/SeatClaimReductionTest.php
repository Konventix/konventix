<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Registration\Events\RegistrationQuantityReduced;
use Konventix\Resources\Actions\AssignPlacesToRegistration;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Events\ResourceBookingReleased;
use Konventix\Resources\Models\ResourceClaim;

/**
 * D-REG-15: a confirmed registration bought fewer units, so its seat
 * entitlement shrinks by the places those units were worth — and gives the
 * surplus places back. Never the whole claim; that is what a cancellation
 * does (`ReleaseSeatClaims`).
 *
 * The listener is registered at boot, ungated like the release listeners
 * beside it, so these tests dispatch the real event.
 *
 * Helper names prefixed `seatReduce*` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
function seatReduceEvent(string $registrationId, string $reductionId, int $seats): RegistrationQuantityReduced
{
    return new RegistrationQuantityReduced(
        registrationId: $registrationId,
        reductionId: $reductionId,
        registrationTypeId: (string) Str::ulid(),
        eventId: (string) Str::ulid(),
        brandId: (string) Str::ulid(),
        registrantUserId: (string) Str::ulid(),
        removedUnits: 1,
        previousQuantity: 3,
        newQuantity: 2,
        // Already multiplied, exactly as `RegistrationConfirmed.grants` is.
        removedGrants: [[
            'kind' => 'seating',
            'role' => 'seat',
            'quantity' => $seats,
            'resource_type_id' => null,
            'resource_type_ids' => [(string) Str::ulid()],
            'seating_mode' => 'pick',
        ]],
        reasonCode: 'registrant-request',
        reducedByUserId: null,
        reducedAt: now()->toIso8601String(),
    );
}

it('shrinks the claim and releases the surplus places, unattended first', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $window = seatingWindow($eventId, $brand->id);
    $type = seatingCategory($eventId);
    ['places' => $places] = seatingPublishedPlan($eventId, $type, 3);

    $registrationId = (string) Str::ulid();
    $claim = seatingClaim($eventId, $registrationId, (string) Str::ulid(), [$type->id], 3, ClaimState::Placed);

    $boundEarly = seatingBooking($places[0], $window, $registrationId, $claim);
    $boundEarly->forceFill(['admission_id' => seatingAdmission($registrationId, $eventId)->id])->save();
    $unboundEarly = seatingBooking($places[1], $window, $registrationId, $claim);
    $unboundLate = seatingBooking($places[2], $window, $registrationId, $claim);

    Event::fake([ResourceBookingReleased::class]);

    event(seatReduceEvent($registrationId, (string) Str::ulid(), 1));

    expect($claim->fresh()->quantity)->toBe(2)
        ->and($claim->fresh()->state)->toBe(ClaimState::Placed)
        // The newest unattended booking goes; the place a ticket is bound to
        // is a promise to a named person and stays.
        ->and($unboundLate->fresh()->state)->toBe(BookingState::Released)
        ->and($unboundEarly->fresh()->state)->toBe(BookingState::Confirmed)
        ->and($boundEarly->fresh()->state)->toBe(BookingState::Confirmed);

    Event::assertDispatched(
        ResourceBookingReleased::class,
        fn (ResourceBookingReleased $e): bool => $e->bookingId === $unboundLate->id
            && $e->reason === 'registration_quantity_reduced',
    );
});

it('never releases the whole claim, however many seats the reduction names', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $window = seatingWindow($eventId, $brand->id);
    $type = seatingCategory($eventId);
    ['places' => $places] = seatingPublishedPlan($eventId, $type, 2);

    $registrationId = (string) Str::ulid();
    $claim = seatingClaim($eventId, $registrationId, (string) Str::ulid(), [$type->id], 2, ClaimState::Placed);

    $first = seatingBooking($places[0], $window, $registrationId, $claim);
    $second = seatingBooking($places[1], $window, $registrationId, $claim);

    event(seatReduceEvent($registrationId, (string) Str::ulid(), 5));

    // Floored at one: the entitlement survives, and the picker still has
    // something to offer.
    expect($claim->fresh()->quantity)->toBe(1)
        ->and($claim->fresh()->state)->toBe(ClaimState::Placed)
        ->and($second->fresh()->state)->toBe(BookingState::Released)
        ->and($first->fresh()->state)->toBe(BookingState::Confirmed);
});

it('leaves the picker able to place the seats that remain', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    seatingWindow($eventId, $brand->id);
    $type = seatingCategory($eventId);
    ['places' => $places] = seatingPublishedPlan($eventId, $type, 3);

    $registrationId = (string) Str::ulid();
    $userId = (string) Str::ulid();
    $claim = seatingClaim($eventId, $registrationId, $userId, [$type->id], 3);

    event(seatReduceEvent($registrationId, (string) Str::ulid(), 1));

    expect($claim->fresh()->quantity)->toBe(2);

    app(AssignPlacesToRegistration::class)->handle(
        claim: $claim->fresh(),
        placeIds: [$places[0]->getKey(), $places[1]->getKey()],
        actorType: AssignPlacesToRegistration::ACTOR_REGISTRANT,
        actorUserId: $userId,
    );

    expect($claim->fresh()->state)->toBe(ClaimState::Placed)
        ->and($claim->fresh()->activeBookings()->count())->toBe(2);
});

it('applies the same reduction only once', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $window = seatingWindow($eventId, $brand->id);
    $type = seatingCategory($eventId);
    ['places' => $places] = seatingPublishedPlan($eventId, $type, 3);

    $registrationId = (string) Str::ulid();
    $reductionId = (string) Str::ulid();
    $claim = seatingClaim($eventId, $registrationId, (string) Str::ulid(), [$type->id], 3, ClaimState::Placed);

    foreach ($places as $place) {
        seatingBooking($place, $window, $registrationId, $claim);
    }

    event(seatReduceEvent($registrationId, $reductionId, 1));
    event(seatReduceEvent($registrationId, $reductionId, 1));

    // A decrement is not a state a replay converges on by itself — the
    // receipt row is what makes the second dispatch a no-op.
    expect($claim->fresh()->quantity)->toBe(2)
        ->and(ResourceClaim::withoutBrandScope()->find($claim->id)->activeBookings()->count())->toBe(2);
});

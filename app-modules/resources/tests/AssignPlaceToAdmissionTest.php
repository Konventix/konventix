<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Platform\Models\Brand;
use Konventix\Resources\Actions\AssignPlaceToAdmission;
use Konventix\Resources\Actions\AssignPlacesToRegistration;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\SeatingMode;
use Konventix\Resources\Events\ResourceBookingReleased;
use Konventix\Resources\Events\SeatClaimPlaced;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\ResourceAdmission;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceClaim;
use Konventix\Resources\Models\ResourceType;

/**
 * A seat per ticket (#292 §6.3): one admission on one place, picked by its
 * holder or by the buyer who manages the group, capped by the claim's
 * quantity across the group.
 *
 * @return array{0: string, 1: ResourceType, 2: Plan}
 */
function admissionSeatScene(Brand $brand): array
{
    $eventId = (string) Str::ulid();
    seatingWindow($eventId, $brand->id);
    $type = ResourceType::factory()->forEvent($eventId)->create();
    $plan = seatingPlan($eventId, ['published_at' => now()]);

    return [$eventId, $type, $plan];
}

function admissionSeatAction(): AssignPlaceToAdmission
{
    return app(AssignPlaceToAdmission::class);
}

/**
 * @return array{0: ResourceClaim, 1: string, 2: string}
 */
function admissionSeatClaim(string $eventId, ResourceType $type, string $brandId, int $quantity = 2): array
{
    $managerId = (string) Str::ulid();
    $registrationId = seatingConfirm($eventId, $brandId, [seatingGrantPayload([$type->id], quantity: $quantity)], registrantUserId: $managerId);

    return [seatingClaimFor($registrationId), $registrationId, $managerId];
}

it('seats a ticket on a place: one confirmed booking bound to the admission, the claim placed, the event after commit', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    [$eventId, $type, $plan] = admissionSeatScene($brand);
    $a1 = seatingPlaceRow($plan, $type, 'A-01');
    [$claim, $registrationId, $managerId] = admissionSeatClaim($eventId, $type, $brand->id);
    $holderId = (string) Str::ulid();
    $admission = seatingAdmission($registrationId, $eventId, $holderId, $managerId);

    Event::fake([SeatClaimPlaced::class]);

    $booking = admissionSeatAction()->handle($claim, $admission, $a1->id, AssignPlaceToAdmission::ACTOR_REGISTRANT, $holderId);

    expect($booking)->not->toBeNull()
        ->and($booking->admission_id)->toBe($admission->id)
        ->and($booking->state)->toBe(BookingState::Confirmed)
        ->and($booking->claim_id)->toBe($claim->id)
        ->and($claim->state)->toBe(ClaimState::Placed);

    Event::assertDispatched(SeatClaimPlaced::class, fn (SeatClaimPlaced $e): bool => $e->placeIds === [$a1->id]);
});

it('lets the manager seat any ticket of the group, and refuses a stranger', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    [$eventId, $type, $plan] = admissionSeatScene($brand);
    $a1 = seatingPlaceRow($plan, $type, 'A-01');
    [$claim, $registrationId, $managerId] = admissionSeatClaim($eventId, $type, $brand->id);
    $admission = seatingAdmission($registrationId, $eventId, (string) Str::ulid(), $managerId);

    expect(fn () => admissionSeatAction()->handle($claim, $admission, $a1->id, AssignPlaceToAdmission::ACTOR_REGISTRANT, (string) Str::ulid()))
        ->toThrow(AuthorizationException::class);

    $booking = admissionSeatAction()->handle($claim, $admission, $a1->id, AssignPlaceToAdmission::ACTOR_REGISTRANT, $managerId);

    expect($booking?->admission_id)->toBe($admission->id);
});

it('moves a ticket to another place, releasing the old one as repicked, and lets it give the seat up', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    [$eventId, $type, $plan] = admissionSeatScene($brand);
    $a1 = seatingPlaceRow($plan, $type, 'A-01');
    $a2 = seatingPlaceRow($plan, $type, 'A-02');
    [$claim, $registrationId, $managerId] = admissionSeatClaim($eventId, $type, $brand->id);
    $admission = seatingAdmission($registrationId, $eventId, null, $managerId);

    $first = admissionSeatAction()->handle($claim, $admission, $a1->id, AssignPlaceToAdmission::ACTOR_REGISTRANT, $managerId);

    Event::fake([ResourceBookingReleased::class]);

    $second = admissionSeatAction()->handle($claim, $admission, $a2->id, AssignPlaceToAdmission::ACTOR_REGISTRANT, $managerId);

    expect($first->fresh()->state)->toBe(BookingState::Released)
        ->and($second->resource_id)->toBe($a2->id);
    Event::assertDispatched(ResourceBookingReleased::class, fn (ResourceBookingReleased $e): bool => $e->bookingId === $first->id && $e->reason === 'repicked');

    $none = admissionSeatAction()->handle($claim, $admission, null, AssignPlaceToAdmission::ACTOR_REGISTRANT, $managerId);

    expect($none)->toBeNull()
        ->and($second->fresh()->state)->toBe(BookingState::Released)
        ->and($claim->state)->toBe(ClaimState::Unplaced);
});

it('caps the group at the claim quantity across bound and unbound bookings, and rebinds an unbound place of its own', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    [$eventId, $type, $plan] = admissionSeatScene($brand);
    $a1 = seatingPlaceRow($plan, $type, 'A-01');
    $a2 = seatingPlaceRow($plan, $type, 'A-02');
    $a3 = seatingPlaceRow($plan, $type, 'A-03');
    [$claim, $registrationId, $managerId] = admissionSeatClaim($eventId, $type, $brand->id, quantity: 2);
    $first = seatingAdmission($registrationId, $eventId, null, $managerId);
    $second = seatingAdmission($registrationId, $eventId, null, $managerId);
    $third = seatingAdmission($registrationId, $eventId, null, $managerId);

    // Two unbound places from the registration-level picker that predates this.
    app(AssignPlacesToRegistration::class)->handle($claim, [$a1->id, $a2->id], AssignPlacesToRegistration::ACTOR_REGISTRANT, $managerId);

    // A third place would exceed the allowance.
    $errors = seatingRefusal(fn () => admissionSeatAction()->handle($claim, $first, $a3->id, AssignPlaceToAdmission::ACTOR_REGISTRANT, $managerId));
    expect($errors['placeIds'][0])->toContain('allowance');

    // But a place the group already holds unbound becomes this ticket's.
    $bound = admissionSeatAction()->handle($claim, $first, $a1->id, AssignPlaceToAdmission::ACTOR_REGISTRANT, $managerId);

    expect($bound->admission_id)->toBe($first->id)
        ->and(ResourceBooking::query()->where('claim_id', $claim->id)->whereIn('state', BookingState::occupyingValues())->count())->toBe(2)
        ->and(ResourceBooking::query()->where('claim_id', $claim->id)->whereNull('admission_id')->whereIn('state', BookingState::occupyingValues())->count())->toBe(1);

    admissionSeatAction()->handle($claim, $second, $a2->id, AssignPlaceToAdmission::ACTOR_REGISTRANT, $managerId);

    $errors = seatingRefusal(fn () => admissionSeatAction()->handle($claim, $third, $a3->id, AssignPlaceToAdmission::ACTOR_REGISTRANT, $managerId));
    expect($errors['placeIds'][0])->toContain('allowance');
});

it('refuses a place another ticket holds, an assign-mode claim, and a revoked or foreign admission', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    [$eventId, $type, $plan] = admissionSeatScene($brand);
    $a1 = seatingPlaceRow($plan, $type, 'A-01');
    [$claim, $registrationId, $managerId] = admissionSeatClaim($eventId, $type, $brand->id);
    $mine = seatingAdmission($registrationId, $eventId, null, $managerId);
    $theirs = seatingAdmission((string) Str::ulid(), $eventId, null, $managerId);
    $revoked = seatingAdmission($registrationId, $eventId, null, $managerId, ResourceAdmission::STATE_REVOKED);

    // Somebody else's group sits on A-01 already.
    [$otherClaim, , $otherManager] = admissionSeatClaim($eventId, $type, $brand->id);
    $other = seatingAdmission($otherClaim->registration_id, $eventId, null, $otherManager);
    admissionSeatAction()->handle($otherClaim, $other, $a1->id, AssignPlaceToAdmission::ACTOR_REGISTRANT, $otherManager);

    $errors = seatingRefusal(fn () => admissionSeatAction()->handle($claim, $mine, $a1->id, AssignPlaceToAdmission::ACTOR_REGISTRANT, $managerId));
    expect($errors['placeIds'][0])->toContain('A-01');

    $errors = seatingRefusal(fn () => admissionSeatAction()->handle($claim, $theirs, $a1->id, AssignPlaceToAdmission::ACTOR_REGISTRANT, $managerId));
    expect($errors['placeIds'][0])->toContain('cannot be seated');

    $errors = seatingRefusal(fn () => admissionSeatAction()->handle($claim, $revoked, $a1->id, AssignPlaceToAdmission::ACTOR_REGISTRANT, $managerId));
    expect($errors['placeIds'][0])->toContain('cannot be seated');

    $claim->forceFill(['seating_mode' => SeatingMode::Assign])->save();
    $errors = seatingRefusal(fn () => admissionSeatAction()->handle($claim->fresh(), $mine, $a1->id, AssignPlaceToAdmission::ACTOR_REGISTRANT, $managerId));
    expect($errors['placeIds'][0])->toContain('organiser');
});

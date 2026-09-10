<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Platform\Models\Brand;
use Konventix\Resources\Actions\AssignPlacesToRegistration;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Events\ResourceBookingReleased;
use Konventix\Resources\Events\SeatClaimPlaced;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceEventWindow;
use Konventix\Resources\Models\ResourceType;

/**
 * Placing a seat claim (seating design §7.3): hold plus confirm per place in
 * one transaction, the exclusion constraint deciding races, every refusal a
 * `placeIds` validation error the picker can show.
 *
 * `assignTest*` helpers are used by this file only; the shared ones live in
 * tests/Helpers.php with the `seating` prefix (.ai/rules/tests.md).
 *
 * @return array{0: string, 1: ResourceType, 2: Plan}
 */
function assignTestScene(Brand $brand): array
{
    $eventId = (string) Str::ulid();
    seatingWindow($eventId, $brand->id);
    $type = ResourceType::factory()->forEvent($eventId)->create();
    $plan = seatingPlan($eventId, ['published_at' => now()]);

    return [$eventId, $type, $plan];
}

function assignTestAction(): AssignPlacesToRegistration
{
    return app(AssignPlacesToRegistration::class);
}

it('places a claim: confirmed bookings, placed claim, event after commit', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    [$eventId, $type, $plan] = assignTestScene($brand);
    $a1 = seatingPlaceRow($plan, $type, 'A-01');
    $a2 = seatingPlaceRow($plan, $type, 'A-02');
    $registrantId = (string) Str::ulid();
    $registrationId = seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id], quantity: 2)], registrantUserId: $registrantId);
    $claim = seatingClaimFor($registrationId);

    Event::fake([SeatClaimPlaced::class]);

    $bookings = assignTestAction()->handle($claim, [$a2->id, $a1->id, $a1->id], AssignPlacesToRegistration::ACTOR_REGISTRANT, $registrantId);

    $window = ResourceEventWindow::forEvent($brand->id, $eventId);

    expect($bookings)->toHaveCount(2)
        ->and($bookings[0]->state)->toBe(BookingState::Confirmed)
        ->and($bookings[0]->holder_type)->toBe(ResourceBooking::HOLDER_REGISTRATION)
        ->and($bookings[0]->holder_id)->toBe($registrationId)
        ->and($bookings[0]->registration_id)->toBe($registrationId)
        ->and($bookings[0]->claim_id)->toBe($claim->id)
        ->and($bookings[0]->quantity)->toBe(1)
        ->and($bookings[0]->starts_at->equalTo($window->starts_at))->toBeTrue()
        ->and($bookings[0]->ends_at->equalTo($window->ends_at))->toBeTrue()
        ->and(collect($bookings)->pluck('resource_id')->sort()->values()->all())->toBe(collect([$a1->id, $a2->id])->sort()->values()->all())
        ->and($claim->fresh()->state)->toBe(ClaimState::Placed);

    Event::assertDispatched(
        SeatClaimPlaced::class,
        fn (SeatClaimPlaced $event): bool => $event->claimId === $claim->id
            && $event->registrationId === $registrationId
            && $event->eventId === $eventId
            && $event->brandId === $brand->id
            && $event->placeIds === collect([$a1->id, $a2->id])->sort()->values()->all(),
    );
});

it('refuses the second registration on the same place with a placeIds error and no booking', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    [$eventId, $type, $plan] = assignTestScene($brand);
    $place = seatingPlaceRow($plan, $type, 'A-01');
    $first = seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id])]);
    $second = seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id])]);

    assignTestAction()->handle(seatingClaimFor($first), [$place->id], AssignPlacesToRegistration::ACTOR_OPERATOR, null);

    // The constraint fires inside HoldResourceBooking's nested transaction
    // (a savepoint), so the database is usable for the assertions after it.
    $errors = seatingRefusal(fn () => assignTestAction()->handle(seatingClaimFor($second), [$place->id], AssignPlacesToRegistration::ACTOR_OPERATOR, null));

    expect($errors['placeIds'][0])->toContain('A-01')
        ->and(ResourceBooking::query()->where('registration_id', $second)->count())->toBe(0)
        ->and(ResourceBooking::query()->where('registration_id', $first)->where('state', BookingState::Confirmed->value)->count())->toBe(1)
        ->and(seatingClaimFor($second)->state)->toBe(ClaimState::Unplaced);
});

it('refuses a place of a category the claim may not choose', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    [$eventId, $type, $plan] = assignTestScene($brand);
    $vip = ResourceType::factory()->forEvent($eventId)->create();
    $place = seatingPlaceRow($plan, $vip, 'V-01');
    $registrationId = seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id])]);

    $errors = seatingRefusal(fn () => assignTestAction()->handle(seatingClaimFor($registrationId), [$place->id], AssignPlacesToRegistration::ACTOR_OPERATOR, null));

    expect($errors['placeIds'][0])->toContain('V-01')
        ->and(ResourceBooking::query()->count())->toBe(0);
});

it('refuses a blocked place', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    [$eventId, $type, $plan] = assignTestScene($brand);
    $place = seatingPlaceRow($plan, $type, 'A-01', ['blocked' => true]);
    $registrationId = seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id])]);

    $errors = seatingRefusal(fn () => assignTestAction()->handle(seatingClaimFor($registrationId), [$place->id], AssignPlacesToRegistration::ACTOR_OPERATOR, null));

    expect($errors['placeIds'][0])->toContain('A-01')
        ->and(ResourceBooking::query()->count())->toBe(0);
});

it('refuses a place of an unpublished plan', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    [$eventId, $type] = assignTestScene($brand);
    $draft = seatingPlan($eventId);
    $place = seatingPlaceRow($draft, $type, 'D-01');
    $registrationId = seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id])]);

    $errors = seatingRefusal(fn () => assignTestAction()->handle(seatingClaimFor($registrationId), [$place->id], AssignPlacesToRegistration::ACTOR_OPERATOR, null));

    expect($errors)->toHaveKey('placeIds')
        ->and(ResourceBooking::query()->count())->toBe(0);
});

it('refuses more places than the claim allows, and an empty pick', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    [$eventId, $type, $plan] = assignTestScene($brand);
    $a1 = seatingPlaceRow($plan, $type, 'A-01');
    $a2 = seatingPlaceRow($plan, $type, 'A-02');
    $registrationId = seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id], quantity: 1)]);

    $errors = seatingRefusal(fn () => assignTestAction()->handle(seatingClaimFor($registrationId), [$a1->id, $a2->id], AssignPlacesToRegistration::ACTOR_OPERATOR, null));
    expect($errors['placeIds'][0])->toContain('at most 1');

    $errors = seatingRefusal(fn () => assignTestAction()->handle(seatingClaimFor($registrationId), [], AssignPlacesToRegistration::ACTOR_OPERATOR, null));
    expect($errors)->toHaveKey('placeIds')
        ->and(ResourceBooking::query()->count())->toBe(0);
});

it('refuses a place of another event', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    [$eventId, $type] = assignTestScene($brand);
    [, , $otherPlan] = assignTestScene($brand);
    $foreign = seatingPlaceRow($otherPlan, $type, 'X-01');
    $registrationId = seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id])]);

    $errors = seatingRefusal(fn () => assignTestAction()->handle(seatingClaimFor($registrationId), [$foreign->id], AssignPlacesToRegistration::ACTOR_OPERATOR, null));

    expect($errors['placeIds'][0])->toContain('X-01')
        ->and(ResourceBooking::query()->count())->toBe(0);
});

it('releases the old bookings with reason repicked on a re-pick', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    [$eventId, $type, $plan] = assignTestScene($brand);
    $a1 = seatingPlaceRow($plan, $type, 'A-01');
    $a2 = seatingPlaceRow($plan, $type, 'A-02');
    $registrationId = seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id])]);
    $claim = seatingClaimFor($registrationId);

    $first = assignTestAction()->handle($claim, [$a1->id], AssignPlacesToRegistration::ACTOR_OPERATOR, null);

    Event::fake([ResourceBookingReleased::class]);

    $second = assignTestAction()->handle($claim, [$a2->id], AssignPlacesToRegistration::ACTOR_OPERATOR, null);

    expect($first[0]->fresh()->state)->toBe(BookingState::Released)
        ->and($second[0]->resource_id)->toBe($a2->id)
        ->and($second[0]->state)->toBe(BookingState::Confirmed)
        ->and($claim->fresh()->state)->toBe(ClaimState::Placed);

    Event::assertDispatched(
        ResourceBookingReleased::class,
        fn (ResourceBookingReleased $event): bool => $event->bookingId === $first[0]->id
            && $event->reason === AssignPlacesToRegistration::REASON_REPICKED,
    );

    // Re-picking the very same place works: the old booking goes first.
    $third = assignTestAction()->handle($claim, [$a2->id], AssignPlacesToRegistration::ACTOR_OPERATOR, null);
    expect($third[0]->id)->not->toBe($second[0]->id)
        ->and(ResourceBooking::query()->where('resource_id', $a2->id)->where('state', BookingState::Confirmed->value)->count())->toBe(1);
});

it('refuses a released claim', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    [$eventId, $type, $plan] = assignTestScene($brand);
    $place = seatingPlaceRow($plan, $type, 'A-01');
    $registrationId = seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id])]);
    $claim = seatingClaimFor($registrationId);
    $claim->forceFill(['state' => ClaimState::Released])->save();

    $errors = seatingRefusal(fn () => assignTestAction()->handle($claim, [$place->id], AssignPlacesToRegistration::ACTOR_OPERATOR, null));

    expect($errors)->toHaveKey('placeIds')
        ->and(ResourceBooking::query()->count())->toBe(0);
});

it('lets only the claim owner act as registrant', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    [$eventId, $type, $plan] = assignTestScene($brand);
    $place = seatingPlaceRow($plan, $type, 'A-01');
    $registrationId = seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id])], registrantUserId: (string) Str::ulid());

    expect(fn () => assignTestAction()->handle(seatingClaimFor($registrationId), [$place->id], AssignPlacesToRegistration::ACTOR_REGISTRANT, (string) Str::ulid()))
        ->toThrow(AuthorizationException::class);

    expect(ResourceBooking::query()->count())->toBe(0);
});

it('requires BookOnBehalf from an operator actor with a user id', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    [$eventId, $type, $plan] = assignTestScene($brand);
    $place = seatingPlaceRow($plan, $type, 'A-01');
    $registrationId = seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id])]);

    expect(fn () => assignTestAction()->handle(seatingClaimFor($registrationId), [$place->id], AssignPlacesToRegistration::ACTOR_OPERATOR, (string) Str::ulid()))
        ->toThrow(AuthorizationException::class);

    $desk = seatingUserWithBookOnBehalf($brand->id);

    $bookings = assignTestAction()->handle(seatingClaimFor($registrationId), [$place->id], AssignPlacesToRegistration::ACTOR_OPERATOR, $desk->id);

    expect($bookings)->toHaveCount(1)
        ->and($bookings[0]->state)->toBe(BookingState::Confirmed);
});

it('maps a deadlock and an exclusion violation to the same refusal', function (): void {
    // A real deadlock needs two connections; the constraint path is proven
    // above. This pins the translation both go through.
    $failure = fn (string $sqlState): QueryException => new QueryException(
        'pgsql',
        'insert into "resource_bookings"',
        [],
        new class('simulated', $sqlState) extends PDOException
        {
            public function __construct(string $message, string $sqlState)
            {
                parent::__construct($message);
                $this->code = $sqlState;
            }
        },
    );

    expect(AssignPlacesToRegistration::isRaceFailure($failure('23P01')))->toBeTrue()
        ->and(AssignPlacesToRegistration::isRaceFailure($failure('40P01')))->toBeTrue()
        ->and(AssignPlacesToRegistration::isRaceFailure($failure('23505')))->toBeFalse();
});

it('refuses with a clear text when the event window is not projected', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    $eventId = (string) Str::ulid();
    seatingWindow($eventId, $brand->id);
    $type = ResourceType::factory()->forEvent($eventId)->create();
    $plan = seatingPlan($eventId, ['published_at' => now()]);
    $place = seatingPlaceRow($plan, $type, 'A-01');
    $registrationId = seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id])]);
    ResourceEventWindow::withoutBrandScope()->where('event_id', $eventId)->delete();

    $errors = seatingRefusal(fn () => assignTestAction()->handle(seatingClaimFor($registrationId), [$place->id], AssignPlacesToRegistration::ACTOR_OPERATOR, null));

    expect($errors['placeIds'][0])->toContain('event window')
        ->and(ResourceBooking::query()->count())->toBe(0);
});

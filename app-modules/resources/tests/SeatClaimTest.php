<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Events\RegistrationRejected;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\SeatingMode;
use Konventix\Resources\Events\SeatClaimCreated;
use Konventix\Resources\Events\SeatClaimReleased;
use Konventix\Resources\Models\ResourceClaim;
use Konventix\Resources\Models\ResourceType;

/**
 * A confirmed registration's seating grant becomes a `resource_claims` row
 * (seating design §7.2, decision D-EVT-06: an unallocated claim needs its own
 * row, never a fake booking). Claims follow the registration out again on
 * cancellation and rejection.
 */
it('creates one unplaced claim for a seating grant on confirmation', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    $eventId = (string) Str::ulid();
    seatingWindow($eventId, $brand->id);
    $type = ResourceType::factory()->forEvent($eventId)->create();
    $registrantId = (string) Str::ulid();

    Event::fake([SeatClaimCreated::class]);

    $registrationId = seatingConfirm($eventId, $brand->id, [
        ['kind' => 'admission', 'role' => 'visitor', 'quantity' => 1, 'resource_type_id' => null, 'resource_type_ids' => null, 'seating_mode' => null],
        seatingGrantPayload([$type->id], quantity: 2, seatingMode: 'pick'),
    ], registrantUserId: $registrantId);

    $claim = seatingClaimFor($registrationId);

    expect($claim->brand_id)->toBe($brand->id)
        ->and($claim->event_id)->toBe($eventId)
        ->and($claim->registrant_user_id)->toBe($registrantId)
        ->and($claim->resource_type_ids)->toBe([$type->id])
        ->and($claim->seating_mode)->toBe(SeatingMode::Pick)
        ->and($claim->quantity)->toBe(2)
        ->and($claim->state)->toBe(ClaimState::Unplaced)
        ->and(ResourceClaim::query()->count())->toBe(1);

    Event::assertDispatched(
        SeatClaimCreated::class,
        fn (SeatClaimCreated $event): bool => $event->claimId === $claim->id
            && $event->registrationId === $registrationId
            && $event->eventId === $eventId
            && $event->brandId === $brand->id,
    );
});

it('is idempotent when the confirmation is replayed', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    $eventId = (string) Str::ulid();
    seatingWindow($eventId, $brand->id);
    $type = ResourceType::factory()->forEvent($eventId)->create();

    $registrationId = seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id])]);
    seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id])], $registrationId);

    expect(ResourceClaim::query()->where('registration_id', $registrationId)->count())->toBe(1);
});

it('creates no claim when the module is off for the brand', function (): void {
    $brand = $this->withBrand();
    // Deliberately not activated.
    $eventId = (string) Str::ulid();
    seatingWindow($eventId, $brand->id);
    $type = ResourceType::factory()->forEvent($eventId)->create();

    seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id])]);

    expect(ResourceClaim::query()->count())->toBe(0);
});

it('creates no claim for admission or resource_claim grants', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    $eventId = (string) Str::ulid();
    seatingWindow($eventId, $brand->id);
    $type = ResourceType::factory()->forEvent($eventId)->create();

    seatingConfirm($eventId, $brand->id, [
        ['kind' => 'admission', 'role' => 'visitor', 'quantity' => 1, 'resource_type_id' => null, 'resource_type_ids' => null, 'seating_mode' => null],
        ['kind' => 'resource_claim', 'role' => 'booth', 'quantity' => 1, 'resource_type_id' => $type->id, 'resource_type_ids' => null, 'seating_mode' => null],
    ]);

    expect(ResourceClaim::query()->count())->toBe(0);
});

it('keys an amendment claim on the amendment and ignores its replay', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    $eventId = (string) Str::ulid();
    seatingWindow($eventId, $brand->id);
    $type = ResourceType::factory()->forEvent($eventId)->create();

    $registrationId = seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id])]);
    $amendmentId = seatingAmend($eventId, $brand->id, $registrationId, [seatingGrantPayload([$type->id], seatingMode: 'assign')]);
    seatingAmend($eventId, $brand->id, $registrationId, [seatingGrantPayload([$type->id], seatingMode: 'assign')], $amendmentId);

    $claims = ResourceClaim::query()->where('registration_id', $registrationId)->orderBy('created_at')->get();

    expect($claims)->toHaveCount(2)
        ->and($claims->first()->amendment_id)->toBeNull()
        ->and($claims->last()->amendment_id)->toBe($amendmentId)
        ->and($claims->last()->seating_mode)->toBe(SeatingMode::Assign);
});

it('releases the claims when the registration is cancelled', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    $eventId = (string) Str::ulid();
    seatingWindow($eventId, $brand->id);
    $type = ResourceType::factory()->forEvent($eventId)->create();
    $registrationId = seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id])]);

    Event::fake([SeatClaimReleased::class]);

    event(new RegistrationCancelled(
        registrationId: $registrationId,
        cancelledByUserId: null,
        reasonCode: 'registrant',
        cancelledAt: now()->toIso8601String(),
    ));

    expect(seatingClaimFor($registrationId)->state)->toBe(ClaimState::Released);

    Event::assertDispatched(
        SeatClaimReleased::class,
        fn (SeatClaimReleased $event): bool => $event->registrationId === $registrationId
            && $event->reason === 'registration_cancelled',
    );
});

it('releases the claims when the registration is rejected', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    $eventId = (string) Str::ulid();
    seatingWindow($eventId, $brand->id);
    $type = ResourceType::factory()->forEvent($eventId)->create();
    $registrationId = seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id])]);

    Event::fake([SeatClaimReleased::class]);

    event(new RegistrationRejected(
        registrationId: $registrationId,
        rejectedByUserId: (string) Str::ulid(),
        reasonCode: 'incomplete',
        rejectedAt: now()->toIso8601String(),
    ));

    expect(seatingClaimFor($registrationId)->state)->toBe(ClaimState::Released);

    Event::assertDispatched(
        SeatClaimReleased::class,
        fn (SeatClaimReleased $event): bool => $event->reason === 'registration_rejected',
    );
});

it('does nothing on a cancellation for a registration without claims, and does not release twice', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');
    $eventId = (string) Str::ulid();
    seatingWindow($eventId, $brand->id);
    $type = ResourceType::factory()->forEvent($eventId)->create();
    $registrationId = seatingConfirm($eventId, $brand->id, [seatingGrantPayload([$type->id])]);

    Event::fake([SeatClaimReleased::class]);

    $cancel = fn (string $id) => event(new RegistrationCancelled(
        registrationId: $id,
        cancelledByUserId: null,
        reasonCode: 'operator',
        cancelledAt: now()->toIso8601String(),
    ));

    $cancel((string) Str::ulid());
    $cancel($registrationId);
    $cancel($registrationId);

    Event::assertDispatchedTimes(SeatClaimReleased::class, 1);
});

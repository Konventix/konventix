<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Konventix\Resources\Actions\ValidatePlan;
use Konventix\Resources\Data\PlanConflictResolutionData;
use Konventix\Resources\Data\PlanSaveData;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\ConflictReason;
use Konventix\Resources\Events\ResourceBookingReleased;
use Konventix\Resources\Exceptions\PlanConflictException;
use Konventix\Resources\Models\ResourceClaim;

/**
 * Design §6.3: deleting, recategorising or blocking an occupied place is a
 * conflict; moving or relabelling it never is. The only resolution is
 * `release` — booking released with reason `plan_conflict`, claim back to
 * `unplaced`. Nothing is written until every conflict has one.
 */
it('refuses to delete an occupied place and lists its booking', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    $b = seatingPlace($type->id, 'A-02', ['x' => 800]);
    seatingSave($plan, seatingDocument([$a, $b]));
    $booking = seatingOccupy(seatingPlaces($plan)->get($a['id']));

    try {
        seatingSave($plan, seatingDocument([$b]));
        $this->fail('Expected PlanConflictException.');
    } catch (PlanConflictException $e) {
        $response = $e->render(request());

        expect($e->conflicts)->toHaveCount(1)
            ->and($e->conflicts[0]->placeId)->toBe($a['id'])
            ->and($e->conflicts[0]->ref)->toBe('A-01')
            ->and($e->conflicts[0]->reason)->toBe(ConflictReason::Deleted)
            ->and($e->conflicts[0]->bookings[0]->bookingId)->toBe($booking->id)
            ->and($e->conflicts[0]->bookings[0]->holderType)->toBe('registration')
            ->and($e->conflicts[0]->bookings[0]->registrationId)->toBe($booking->registration_id)
            ->and($e->conflicts[0]->bookings[0]->claimId)->toBe($booking->claim_id)
            ->and($response->getStatusCode())->toBe(422)
            ->and($response->getData(true)['conflicts'][0]['bookings'][0]['bookingId'])->toBe($booking->id);
    }

    expect($booking->fresh()->state)->toBe(BookingState::Confirmed)
        ->and(seatingPlaces($plan)->get($a['id'])->deleted_at)->toBeNull()
        ->and($plan->fresh()->version)->toBe(1);
});

it('refuses to recategorise an occupied place', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $vip = seatingCategory($eventId, 'vip');
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    seatingSave($plan, seatingDocument([$a]));
    seatingOccupy(seatingPlaces($plan)->get($a['id']));

    try {
        seatingSave($plan, seatingDocument([array_merge($a, ['resourceTypeId' => $vip->id])]));
        $this->fail('Expected PlanConflictException.');
    } catch (PlanConflictException $e) {
        expect($e->conflicts[0]->reason)->toBe(ConflictReason::CategoryChanged);
    }

    expect(seatingPlaces($plan)->get($a['id'])->resource_type_id)->toBe($type->id);
});

it('refuses to block an occupied place', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    seatingSave($plan, seatingDocument([$a]));
    seatingOccupy(seatingPlaces($plan)->get($a['id']));

    try {
        seatingSave($plan, seatingDocument([array_merge($a, ['blocked' => true])]));
        $this->fail('Expected PlanConflictException.');
    } catch (PlanConflictException $e) {
        expect($e->conflicts[0]->reason)->toBe(ConflictReason::Blocked);
    }

    expect(seatingPlaces($plan)->get($a['id'])->blocked)->toBeFalse();
});

it('releases the booking and unplaces the claim when the operator resolves with release', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    $b = seatingPlace($type->id, 'A-02', ['x' => 800]);
    seatingSave($plan, seatingDocument([$a, $b]));
    $booking = seatingOccupy(seatingPlaces($plan)->get($a['id']));

    $seen = [];
    Event::listen(ResourceBookingReleased::class, function (ResourceBookingReleased $event) use (&$seen): void {
        $seen[] = [$event->bookingId, $event->reason, DB::transactionLevel()];
    });

    $result = seatingSave($plan, seatingDocument([$b]), [
        new PlanConflictResolutionData(placeId: $a['id'], resolution: 'release'),
    ]);

    expect($result->version)->toBe(2)
        ->and($booking->fresh()->state)->toBe(BookingState::Released)
        ->and(ResourceClaim::query()->findOrFail($booking->claim_id)->state)->toBe(ClaimState::Unplaced)
        ->and(seatingPlaces($plan)->get($a['id'])->deleted_at)->not->toBeNull()
        // RefreshDatabase's wrapper is level 1: the listener ran after
        // SavePlan's commit, not inside the nested release.
        ->and($seen)->toBe([[$booking->id, 'plan_conflict', 1]]);
});

it('lets an occupied place move and be relabelled without a conflict', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    seatingSave($plan, seatingDocument([$a]));
    $booking = seatingOccupy(seatingPlaces($plan)->get($a['id']));

    Event::fake([ResourceBookingReleased::class]);

    $result = seatingSave($plan, seatingDocument([
        array_merge($a, ['x' => 5000, 'y' => 3000, 'rotation' => 90, 'label' => 'Front 1', 'shape' => ['type' => 'rect', 'w' => 500, 'h' => 500]]),
    ]));

    expect($result->version)->toBe(2)
        ->and($booking->fresh()->state)->toBe(BookingState::Confirmed)
        ->and(seatingPlaces($plan)->get($a['id'])->label)->toBe('Front 1');

    Event::assertNotDispatched(ResourceBookingReleased::class);
});

it('reports the same conflicts from the dry run without writing', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    $b = seatingPlace($type->id, 'A-02', ['x' => 800]);
    seatingSave($plan, seatingDocument([$a, $b]));
    $booking = seatingOccupy(seatingPlaces($plan)->get($a['id']));

    $validation = app(ValidatePlan::class)->handle(
        $plan,
        new PlanSaveData(expectedVersion: 1, document: seatingDocument([$b]), resolutions: []),
    );

    expect($validation->conflicts)->toHaveCount(1)
        ->and($validation->conflicts[0]->placeId)->toBe($a['id'])
        ->and($validation->conflicts[0]->reason)->toBe(ConflictReason::Deleted)
        ->and($validation->conflicts[0]->bookings[0]->bookingId)->toBe($booking->id)
        ->and($validation->diff->removed)->toBe(1)
        ->and($booking->fresh()->state)->toBe(BookingState::Confirmed)
        ->and(seatingPlaces($plan))->toHaveCount(2)
        ->and($plan->fresh()->version)->toBe(1);
});

it('ignores a resolution for a place that is not in conflict', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    seatingSave($plan, seatingDocument([$a]));
    $booking = seatingOccupy(seatingPlaces($plan)->get($a['id']));

    seatingSave($plan, seatingDocument([array_merge($a, ['x' => 1600])]), [
        new PlanConflictResolutionData(placeId: $a['id'], resolution: 'release'),
    ]);

    expect($booking->fresh()->state)->toBe(BookingState::Confirmed)
        ->and(ResourceClaim::query()->findOrFail($booking->claim_id)->state)->toBe(ClaimState::Placed);
});

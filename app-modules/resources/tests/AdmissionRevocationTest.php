<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Admission\Events\AdmissionHolderUnassigned;
use Konventix\Admission\Events\AdmissionRevoked;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Events\ResourceBookingReleased;

/**
 * A revoked admission gives its place back; a holder change keeps it
 * (#292 §6.5).
 */
it('releases the booking bound to a revoked admission, and only that one', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $window = seatingWindow($eventId, $brand->id);
    $type = seatingCategory($eventId);
    ['places' => $places] = seatingPublishedPlan($eventId, $type, 3);
    $registrationId = (string) Str::ulid();
    $claim = seatingClaim($eventId, $registrationId, (string) Str::ulid(), [$type->id], 3);
    $revoked = seatingAdmission($registrationId, $eventId);
    $kept = seatingAdmission($registrationId, $eventId);

    $revokedSeat = seatingBooking($places[0], $window, $registrationId, $claim);
    $revokedSeat->forceFill(['admission_id' => $revoked->id])->save();
    $keptSeat = seatingBooking($places[1], $window, $registrationId, $claim);
    $keptSeat->forceFill(['admission_id' => $kept->id])->save();
    $unbound = seatingBooking($places[2], $window, $registrationId, $claim);

    Event::fake([ResourceBookingReleased::class]);

    event(new AdmissionRevoked(
        admissionId: $revoked->id,
        registrationId: $registrationId,
        eventId: $eventId,
        brandId: $brand->id,
        revokedByUserId: null,
        reasonCode: 'registration_cancelled',
        revokedAt: now()->toIso8601String(),
    ));

    expect($revokedSeat->fresh()->state)->toBe(BookingState::Released)
        ->and($keptSeat->fresh()->state)->toBe(BookingState::Confirmed)
        ->and($unbound->fresh()->state)->toBe(BookingState::Confirmed);

    Event::assertDispatched(ResourceBookingReleased::class, fn (ResourceBookingReleased $e): bool => $e->bookingId === $revokedSeat->id && $e->reason === 'admission_revoked');
});

it('keeps the seat when the holder merely changes', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $window = seatingWindow($eventId, $brand->id);
    $type = seatingCategory($eventId);
    ['places' => $places] = seatingPublishedPlan($eventId, $type, 1);
    $registrationId = (string) Str::ulid();
    $claim = seatingClaim($eventId, $registrationId, (string) Str::ulid(), [$type->id]);
    $admission = seatingAdmission($registrationId, $eventId, (string) Str::ulid());
    $seat = seatingBooking($places[0], $window, $registrationId, $claim);
    $seat->forceFill(['admission_id' => $admission->id])->save();

    event(new AdmissionHolderUnassigned(
        admissionId: $admission->id,
        registrationId: $registrationId,
        eventId: $eventId,
        brandId: $brand->id,
        previousHolderUserId: (string) $admission->holder_user_id,
        unassignedByUserId: null,
        reasonCode: 'manager_unassigned',
        unassignedAt: now()->toIso8601String(),
    ));

    expect($seat->fresh()->state)->toBe(BookingState::Confirmed)
        ->and($seat->fresh()->admission_id)->toBe($admission->id);
});

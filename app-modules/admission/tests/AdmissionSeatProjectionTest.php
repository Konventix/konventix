<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Models\AdmissionSeat;
use Konventix\Resources\Events\ResourceBookingConfirmed;
use Konventix\Resources\Events\ResourceBookingReleased;

/**
 * `admission_seats` (#292 §5): the place an admission sits on, off Resources'
 * confirmed booking, gone again when that booking is released — and only
 * that booking, so a re-pick's release cannot erase the new seat.
 */
function admissionSeatConfirmed(string $brandId, string $admissionId, string $bookingId, ?string $ref = 'A-01'): ResourceBookingConfirmed
{
    return new ResourceBookingConfirmed(
        bookingId: $bookingId,
        resourceId: (string) Str::ulid(),
        brandId: $brandId,
        holderType: 'registration',
        holderId: (string) Str::ulid(),
        registrationId: (string) Str::ulid(),
        quantity: 1,
        startsAt: now()->toIso8601String(),
        endsAt: now()->addDay()->toIso8601String(),
        confirmedAt: now()->toIso8601String(),
        eventId: (string) Str::ulid(),
        resourceTypeId: (string) Str::ulid(),
        claimId: (string) Str::ulid(),
        label: 'Row A, seat 1',
        ref: $ref,
        admissionId: $admissionId,
    );
}

function admissionSeatReleased(ResourceBookingConfirmed $confirmed): ResourceBookingReleased
{
    return new ResourceBookingReleased(
        bookingId: $confirmed->bookingId,
        resourceId: $confirmed->resourceId,
        brandId: $confirmed->brandId,
        holderType: $confirmed->holderType,
        holderId: $confirmed->holderId,
        reason: 'repicked',
        releasedAt: now()->toIso8601String(),
        eventId: $confirmed->eventId,
        resourceTypeId: $confirmed->resourceTypeId,
        registrationId: $confirmed->registrationId,
        claimId: $confirmed->claimId,
        label: $confirmed->label,
        ref: $confirmed->ref,
        admissionId: $confirmed->admissionId,
    );
}

it('projects a confirmed booking that names an admission, follows a re-pick, and ignores one that names none', function (): void {
    $brand = $this->withBrand();
    $admissionId = (string) Str::ulid();

    $first = admissionSeatConfirmed($brand->id, $admissionId, (string) Str::ulid());
    event($first);

    $seat = AdmissionSeat::query()->where('admission_id', $admissionId)->firstOrFail();
    expect($seat->booking_id)->toBe($first->bookingId)
        ->and($seat->place_ref)->toBe('A-01')
        ->and($seat->place_label)->toBe('A-01')
        ->and($seat->brand_id)->toBe($brand->id);

    // Re-pick: the new booking confirms, then the old one's release arrives.
    $second = admissionSeatConfirmed($brand->id, $admissionId, (string) Str::ulid(), 'B-07');
    event($second);
    event(admissionSeatReleased($first));

    $seat->refresh();
    expect(AdmissionSeat::query()->where('admission_id', $admissionId)->count())->toBe(1)
        ->and($seat->booking_id)->toBe($second->bookingId)
        ->and($seat->place_label)->toBe('B-07');

    // Given up: the current booking's release removes the row.
    event(admissionSeatReleased($second));
    expect(AdmissionSeat::query()->where('admission_id', $admissionId)->exists())->toBeFalse();

    // Nobody's seat.
    $unbound = admissionSeatConfirmed($brand->id, $admissionId, (string) Str::ulid());
    event(new ResourceBookingConfirmed(...array_merge((array) $unbound, ['admissionId' => null])));
    expect(AdmissionSeat::query()->count())->toBe(0);
});

it('falls back to the label when the place has no ref', function (): void {
    $brand = $this->withBrand();
    $admissionId = (string) Str::ulid();

    event(admissionSeatConfirmed($brand->id, $admissionId, (string) Str::ulid(), null));

    expect(AdmissionSeat::query()->where('admission_id', $admissionId)->value('place_label'))->toBe('Row A, seat 1');
});

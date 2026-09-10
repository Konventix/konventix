<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Resources\Models\ResourceBooking;

/**
 * ADR-0036: an amendment adds grants to a confirmed registration and
 * `RegistrationAmended` carries only the delta plus an `amendmentId`. Before
 * S0 the idempotency guard in `FulfilResourceClaims` was "any booking for
 * this registration", so every amendment was silently dropped; the guard is
 * now per `(registration_id, amendment_id)`, the shape Admission uses.
 */
it('fulfils a resource_claim grant added by an amendment and records the amendment on the booking', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');

    $eventId = (string) Str::ulid();
    seatingProjectWindow($eventId, $brand->id);
    $type = seatingUnitType($eventId);
    seatingPublishedResource($type, 'B-01');

    $registrationId = seatingConfirmRegistration($eventId, $brand->id, [
        ['kind' => 'admission', 'role' => 'visitor', 'quantity' => 1, 'resource_type_id' => null, 'resource_type_ids' => null, 'seating_mode' => null],
    ]);

    expect(ResourceBooking::query()->count())->toBe(0);

    $amendmentId = seatingAmendRegistration($eventId, $brand->id, [seatingClaimGrant($type->id)], $registrationId);

    $booking = ResourceBooking::query()->where('registration_id', $registrationId)->firstOrFail();

    expect($booking->amendment_id)->toBe($amendmentId)
        ->and($booking->label)->toBe('B-01');
});

it('fulfils an amendment on a registration that already holds bookings from its confirmation', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');

    $eventId = (string) Str::ulid();
    seatingProjectWindow($eventId, $brand->id);
    $type = seatingUnitType($eventId);
    seatingPublishedResource($type, 'B-01');
    seatingPublishedResource($type, 'B-02');

    $registrationId = seatingConfirmRegistration($eventId, $brand->id, [seatingClaimGrant($type->id)]);
    $amendmentId = seatingAmendRegistration($eventId, $brand->id, [seatingClaimGrant($type->id)], $registrationId);

    $bookings = ResourceBooking::query()->where('registration_id', $registrationId)->orderBy('label')->get();

    expect($bookings)->toHaveCount(2)
        ->and($bookings[0]->amendment_id)->toBeNull()
        ->and($bookings[1]->amendment_id)->toBe($amendmentId)
        ->and($bookings->pluck('resource_id')->unique())->toHaveCount(2);
});

it('adds nothing when the same amendment is replayed', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');

    $eventId = (string) Str::ulid();
    seatingProjectWindow($eventId, $brand->id);
    $type = seatingUnitType($eventId);
    seatingPublishedResource($type, 'B-01');
    seatingPublishedResource($type, 'B-02');

    $registrationId = seatingConfirmRegistration($eventId, $brand->id, []);
    $amendmentId = seatingAmendRegistration($eventId, $brand->id, [seatingClaimGrant($type->id)], $registrationId);
    seatingAmendRegistration($eventId, $brand->id, [seatingClaimGrant($type->id)], $registrationId, $amendmentId);

    expect(ResourceBooking::query()->where('registration_id', $registrationId)->count())->toBe(1);
});

it('leaves an amendment unfulfilled while the module is off for the brand', function (): void {
    $brand = $this->withBrand();

    $eventId = (string) Str::ulid();
    seatingProjectWindow($eventId, $brand->id);
    $type = seatingUnitType($eventId);
    seatingPublishedResource($type, 'B-01');

    $registrationId = (string) Str::ulid();
    seatingAmendRegistration($eventId, $brand->id, [seatingClaimGrant($type->id)], $registrationId);

    expect(ResourceBooking::query()->count())->toBe(0);
});

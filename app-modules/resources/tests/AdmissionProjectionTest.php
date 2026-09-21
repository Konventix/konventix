<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Events\AdmissionHolderAssigned;
use Konventix\Admission\Events\AdmissionHolderUnassigned;
use Konventix\Admission\Events\AdmissionIssued;
use Konventix\Admission\Events\AdmissionOwnershipAnnounced;
use Konventix\Admission\Events\AdmissionRevoked;
use Konventix\Resources\Models\ResourceAdmission;

/**
 * `resource_admissions` (#292 §6.1): Resources' copy of who holds and who
 * bought each admission, fed by Admission's events and by the backfill
 * announcement, converging on one row per admission in any order.
 */
function seatingAdmissionIssued(string $admissionId, string $brandId, ?string $buyer = null, ?string $holder = null, ?string $registrationId = null): AdmissionIssued
{
    return new AdmissionIssued(
        admissionId: $admissionId,
        registrationId: $registrationId ?? (string) Str::ulid(),
        eventId: (string) Str::ulid(),
        brandId: $brandId,
        role: 'visitor',
        holderUserId: $holder,
        issuedAt: now()->toIso8601String(),
        buyerUserId: $buyer,
    );
}

it('projects an issued admission with its buyer and follows the holder through assignment and removal', function (): void {
    $brand = $this->withBrand();
    $admissionId = (string) Str::ulid();
    $buyer = (string) Str::ulid();
    $holder = (string) Str::ulid();
    $issued = seatingAdmissionIssued($admissionId, $brand->id, $buyer);

    event($issued);

    $row = ResourceAdmission::query()->findOrFail($admissionId);
    expect($row->brand_id)->toBe($brand->id)
        ->and($row->registration_id)->toBe($issued->registrationId)
        ->and($row->event_id)->toBe($issued->eventId)
        ->and($row->buyer_user_id)->toBe($buyer)
        ->and($row->holder_user_id)->toBeNull()
        ->and($row->state)->toBe('issued');

    event(new AdmissionHolderAssigned(
        admissionId: $admissionId,
        registrationId: $issued->registrationId,
        eventId: $issued->eventId,
        brandId: $brand->id,
        holderUserId: $holder,
        viaClaimLink: true,
        assignedAt: now()->toIso8601String(),
    ));

    $row->refresh();
    expect($row->holder_user_id)->toBe($holder)
        ->and($row->state)->toBe('assigned')
        ->and($row->buyer_user_id)->toBe($buyer);

    event(new AdmissionHolderUnassigned(
        admissionId: $admissionId,
        registrationId: $issued->registrationId,
        eventId: $issued->eventId,
        brandId: $brand->id,
        previousHolderUserId: $holder,
        unassignedByUserId: $buyer,
        reasonCode: 'manager_unassigned',
        unassignedAt: now()->toIso8601String(),
    ));

    $row->refresh();
    expect($row->holder_user_id)->toBeNull()
        ->and($row->state)->toBe('issued');

    event(new AdmissionRevoked(
        admissionId: $admissionId,
        registrationId: $issued->registrationId,
        eventId: $issued->eventId,
        brandId: $brand->id,
        revokedByUserId: null,
        reasonCode: 'registration_cancelled',
        revokedAt: now()->toIso8601String(),
    ));

    expect($row->refresh()->isRevoked())->toBeTrue()
        ->and(ResourceAdmission::query()->count())->toBe(1);
});

it('backfills from an ownership announcement and treats one that arrives before the issue the same', function (): void {
    $brand = $this->withBrand();
    $admissionId = (string) Str::ulid();
    $registrationId = (string) Str::ulid();
    $eventId = (string) Str::ulid();
    $holder = (string) Str::ulid();

    event(new AdmissionOwnershipAnnounced(
        admissionId: $admissionId,
        registrationId: $registrationId,
        eventId: $eventId,
        brandId: $brand->id,
        holderUserId: $holder,
        buyerUserId: null,
        state: 'assigned',
    ));

    $row = ResourceAdmission::query()->findOrFail($admissionId);
    expect($row->holder_user_id)->toBe($holder)
        ->and($row->state)->toBe('assigned');

    // The issue arriving afterwards (a replay out of order) does not undo it.
    event(seatingAdmissionIssued($admissionId, $brand->id, (string) Str::ulid(), null, $registrationId));

    expect(ResourceAdmission::query()->count())->toBe(1)
        ->and($row->refresh()->buyer_user_id)->not->toBeNull();

    // An `expired` snapshot still stands; a `revoked` one does not.
    event(new AdmissionOwnershipAnnounced($admissionId, $registrationId, $eventId, $brand->id, $holder, null, 'expired'));
    expect($row->refresh()->state)->toBe('assigned');
    event(new AdmissionOwnershipAnnounced($admissionId, $registrationId, $eventId, $brand->id, $holder, null, 'revoked'));
    expect($row->refresh()->isRevoked())->toBeTrue();
});

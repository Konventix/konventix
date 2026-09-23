<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Models\RegistrationSummary;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationStandingAnnounced;

/**
 * Admission's read model of a registration's type and status (attendee-list
 * design §3, §4.4, #329), projected from Registration's events only
 * (ADR-0004). No factory ships with the model, so every row here comes from
 * an emitted event, as in `EventSummaryProjectionTest`.
 */
function attendeeSummaryConfirmedEvent(
    string $registrationId,
    string $brandId,
    string $registrationTypeId,
    ?string $registrationTypeName = 'Weekend Pass',
): RegistrationConfirmed {
    return new RegistrationConfirmed(
        registrationId: $registrationId,
        registrationTypeId: $registrationTypeId,
        eventId: (string) Str::ulid(),
        brandId: $brandId,
        registrantUserId: (string) Str::ulid(),
        confirmedAt: now()->toIso8601String(),
        grants: [],
        registrationTypeName: $registrationTypeName,
    );
}

function attendeeSummaryCancelledEvent(string $registrationId): RegistrationCancelled
{
    return new RegistrationCancelled(
        registrationId: $registrationId,
        cancelledByUserId: null,
        reasonCode: 'registrant-withdrew',
        cancelledAt: now()->toIso8601String(),
    );
}

it('projects a confirmation with the type id, the type name and the status confirmed', function (): void {
    $brand = $this->withBrand();
    $registrationId = (string) Str::ulid();
    $typeId = (string) Str::ulid();

    event(attendeeSummaryConfirmedEvent($registrationId, $brand->id, $typeId));

    $summary = RegistrationSummary::query()->where('registration_id', $registrationId)->sole();

    // By hand: every value is what the payload carried; the status is the
    // constant a confirmation writes, 'confirmed'.
    expect($summary->brand_id)->toBe($brand->id)
        ->and($summary->registration_type_id)->toBe($typeId)
        ->and($summary->type_name)->toBe('Weekend Pass')
        ->and($summary->status)->toBe('confirmed');
});

it('flips the status to cancelled on a cancellation and keeps the type', function (): void {
    $brand = $this->withBrand();
    $registrationId = (string) Str::ulid();
    $typeId = (string) Str::ulid();

    event(attendeeSummaryConfirmedEvent($registrationId, $brand->id, $typeId));
    event(attendeeSummaryCancelledEvent($registrationId));

    $summary = RegistrationSummary::query()->where('registration_id', $registrationId)->sole();

    // By hand: the cancellation only writes status = 'cancelled'; the type
    // id and 'Weekend Pass' stay from the confirmation.
    expect($summary->status)->toBe('cancelled')
        ->and($summary->registration_type_id)->toBe($typeId)
        ->and($summary->type_name)->toBe('Weekend Pass');
});

it('writes nothing when an unknown registration is cancelled, because the cancellation carries no brand', function (): void {
    $this->withBrand();
    $registrationId = (string) Str::ulid();

    event(attendeeSummaryCancelledEvent($registrationId));

    // By hand: no row existed, and a cancellation never creates one: 0 rows.
    expect(RegistrationSummary::withoutBrandScope()->where('registration_id', $registrationId)->count())->toBe(0);
});

it('leaves one row when the confirmation is replayed', function (): void {
    $brand = $this->withBrand();
    $registrationId = (string) Str::ulid();
    $typeId = (string) Str::ulid();

    event(attendeeSummaryConfirmedEvent($registrationId, $brand->id, $typeId));
    event(attendeeSummaryConfirmedEvent($registrationId, $brand->id, $typeId));

    // By hand: two emissions for one registration_id, keyed unique: 1 row.
    expect(RegistrationSummary::withoutBrandScope()->where('registration_id', $registrationId)->count())->toBe(1);
});

it('backfills a registration confirmed before this deploy from the standing announcement', function (): void {
    $brand = $this->withBrand();
    $registrationId = (string) Str::ulid();
    $typeId = (string) Str::ulid();

    // No confirmation was ever projected: the registration predates the table.
    event(new RegistrationStandingAnnounced(
        registrationId: $registrationId,
        eventId: (string) Str::ulid(),
        brandId: $brand->id,
        registrantUserId: (string) Str::ulid(),
        confirmed: true,
        registrationTypeId: $typeId,
        registrationTypeName: 'Day Ticket',
        status: 'confirmed',
    ));

    $summary = RegistrationSummary::query()->where('registration_id', $registrationId)->sole();

    // By hand: the row is created from the announcement alone, with its
    // brand, its type id, 'Day Ticket' and the carried status 'confirmed'.
    expect($summary->brand_id)->toBe($brand->id)
        ->and($summary->registration_type_id)->toBe($typeId)
        ->and($summary->type_name)->toBe('Day Ticket')
        ->and($summary->status)->toBe('confirmed');
});

it('keeps the stored type name when a standing announcement carries none, and falls back on confirmed for the status', function (): void {
    $brand = $this->withBrand();
    $registrationId = (string) Str::ulid();
    $typeId = (string) Str::ulid();

    event(attendeeSummaryConfirmedEvent($registrationId, $brand->id, $typeId));

    // An emitter that predates the three new fields: no type, no status.
    event(new RegistrationStandingAnnounced(
        registrationId: $registrationId,
        eventId: (string) Str::ulid(),
        brandId: $brand->id,
        registrantUserId: (string) Str::ulid(),
        confirmed: false,
    ));

    $summary = RegistrationSummary::query()->where('registration_id', $registrationId)->sole();

    // By hand: the null type id and name are "not carried", so the
    // confirmation's type id and 'Weekend Pass' stay. `status` is null, so
    // it falls back on `confirmed: false`, which is 'unconfirmed'.
    expect($summary->registration_type_id)->toBe($typeId)
        ->and($summary->type_name)->toBe('Weekend Pass')
        ->and($summary->status)->toBe('unconfirmed');
});

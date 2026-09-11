<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Models\Admission;
use Konventix\Registration\Events\RegistrationOwnershipAnnounced;
use Konventix\Registration\Models\Registration;

/**
 * `FillBuyerOnRegistrationOwnershipAnnounced` backfills
 * `admissions.buyer_user_id` for rows written before that column existed
 * (design doc §13, 2026-09-11 amendment "a registration-derived admission
 * is anonymous today"). Its whole contract is a `whereNull` guard: it never
 * overwrites a buyer `IssueAdmissionsForRegistration` already set.
 */
it('fills a null buyer_user_id and leaves an unrelated admission untouched', function (): void {
    $this->withBrand();
    $registrationId = (string) Str::ulid();
    $registrantUserId = (string) Str::ulid();

    $admission = Admission::factory()->forRegistration($registrationId)->create();
    $unrelated = Admission::factory()->create();

    expect($admission->buyer_user_id)->toBeNull();

    event(new RegistrationOwnershipAnnounced(
        registrationId: $registrationId,
        brandId: $admission->brand_id,
        registrantUserId: $registrantUserId,
    ));

    expect($admission->fresh()->buyer_user_id)->toBe($registrantUserId)
        ->and($unrelated->fresh()->buyer_user_id)->toBeNull();
});

it('leaves an already-set buyer_user_id alone', function (): void {
    $this->withBrand();
    $registrationId = (string) Str::ulid();
    $originalBuyer = (string) Str::ulid();

    $admission = Admission::factory()->forRegistration($registrationId)->create(['buyer_user_id' => $originalBuyer]);

    event(new RegistrationOwnershipAnnounced(
        registrationId: $registrationId,
        brandId: $admission->brand_id,
        registrantUserId: (string) Str::ulid(),
    ));

    expect($admission->fresh()->buyer_user_id)->toBe($originalBuyer);
});

it('is idempotent: replaying the same announcement twice changes nothing further', function (): void {
    $this->withBrand();
    $registrationId = (string) Str::ulid();
    $registrantUserId = (string) Str::ulid();

    $admission = Admission::factory()->forRegistration($registrationId)->create();

    $announcement = new RegistrationOwnershipAnnounced(
        registrationId: $registrationId,
        brandId: $admission->brand_id,
        registrantUserId: $registrantUserId,
    );

    event($announcement);
    event($announcement);

    expect($admission->fresh()->buyer_user_id)->toBe($registrantUserId);
});

it('backfills through the real registration:announce-ownership command end to end', function (): void {
    $this->withBrand();
    $registration = Registration::factory()->confirmed()->create();
    $admission = Admission::factory()->forRegistration($registration->id)->create();

    expect($admission->buyer_user_id)->toBeNull();

    $this->artisan('registration:announce-ownership')->assertSuccessful();

    expect($admission->fresh()->buyer_user_id)->toBe($registration->registrant_user_id);
});

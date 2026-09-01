<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Community\Models\CommunityEventParticipation;
use Konventix\Community\Models\CommunityProfile;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationRejected;
use Konventix\Registration\Events\RegistrationSubmitted;

/**
 * `community_event_participations` (ADR-0052 §3), projected from
 * Registration's contract events (ADR-0004: Community may not read
 * Registration's models). Rows exist only through the projection, so every
 * case emits the Registration event instead of inventing a row.
 */
function registrationSubmittedFor(string $registrationId, string $brandId, string $eventId, string $userId): RegistrationSubmitted
{
    return new RegistrationSubmitted(
        registrationId: $registrationId,
        registrationTypeId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        registrantUserId: $userId,
        settlement: 'free',
        confirmationGate: 'automatic',
        submittedAt: now()->toIso8601String(),
    );
}

it('creates an unconfirmed participation row from RegistrationSubmitted, keyed by registrationId', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $registrationId = (string) Str::ulid();
    $eventId = (string) Str::ulid();
    $userId = (string) Str::ulid();

    event(registrationSubmittedFor($registrationId, $brand->getKey(), $eventId, $userId));

    $participation = CommunityEventParticipation::withoutBrandScope()->findOrFail($registrationId);

    expect($participation->brand_id)->toBe($brand->getKey())
        ->and($participation->user_id)->toBe($userId)
        ->and($participation->event_id)->toBe($eventId)
        ->and($participation->confirmed)->toBeFalse();
});

it('short-circuits silently when the module is off for the event brand', function (): void {
    $brand = $this->withBrand();

    $registrationId = (string) Str::ulid();

    event(registrationSubmittedFor($registrationId, $brand->getKey(), (string) Str::ulid(), (string) Str::ulid()));

    expect(CommunityEventParticipation::withoutBrandScope()->find($registrationId))->toBeNull();
});

it('flips confirmed true by registrationId on RegistrationConfirmed', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $registrationId = (string) Str::ulid();
    $eventId = (string) Str::ulid();

    event(registrationSubmittedFor($registrationId, $brand->getKey(), $eventId, (string) Str::ulid()));

    event(new RegistrationConfirmed(
        registrationId: $registrationId,
        registrationTypeId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brand->getKey(),
        confirmedAt: now()->toIso8601String(),
        grants: [],
    ));

    $participation = CommunityEventParticipation::withoutBrandScope()->findOrFail($registrationId);

    expect($participation->confirmed)->toBeTrue();
});

it('deletes the row by registrationId on RegistrationCancelled', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $registrationId = (string) Str::ulid();

    event(registrationSubmittedFor($registrationId, $brand->getKey(), (string) Str::ulid(), (string) Str::ulid()));

    event(new RegistrationCancelled(
        registrationId: $registrationId,
        cancelledByUserId: (string) Str::ulid(),
        reasonCode: 'registrant_request',
        cancelledAt: now()->toIso8601String(),
    ));

    expect(CommunityEventParticipation::withoutBrandScope()->find($registrationId))->toBeNull();
});

it('deletes the row by registrationId on RegistrationRejected', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $registrationId = (string) Str::ulid();

    event(registrationSubmittedFor($registrationId, $brand->getKey(), (string) Str::ulid(), (string) Str::ulid()));

    event(new RegistrationRejected(
        registrationId: $registrationId,
        rejectedByUserId: (string) Str::ulid(),
        reasonCode: 'ineligible',
        rejectedAt: now()->toIso8601String(),
    ));

    expect(CommunityEventParticipation::withoutBrandScope()->find($registrationId))->toBeNull();
});

it('projects visibility from UserProfileUpdated into community_profiles', function (): void {
    $brand = $this->withBrand();
    $userId = (string) Str::ulid();

    event(new UserRegistered(
        userId: $userId,
        brandId: $brand->getKey(),
        email: 'ada@example.test',
        displayName: 'Ada Lovelace',
        username: 'ada-lovelace',
        locale: 'en',
        registeredAt: now()->toIso8601String(),
    ));

    expect(CommunityProfile::query()->findOrFail($userId)->visibility)->toBe('internal');

    event(new UserProfileUpdated(
        userId: $userId,
        email: 'ada@example.test',
        displayName: 'Ada Lovelace',
        username: 'ada-lovelace',
        tagline: null,
        statusEmoji: null,
        socialLinks: [],
        visibility: 'public',
        locale: 'en',
        updatedAt: now()->toIso8601String(),
    ));

    expect(CommunityProfile::query()->findOrFail($userId)->visibility)->toBe('public');
});

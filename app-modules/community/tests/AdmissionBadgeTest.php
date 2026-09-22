<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Events\ParticipantAdmissionReplaced;
use Konventix\Admission\Events\ParticipantHubAccessEnded;
use Konventix\Admission\Events\ParticipantPresenceChanged;
use Konventix\Community\Models\EventPresence;

/**
 * `community_event_presence` (design §4.5, ADR-0067 decision 2), projected
 * from Admission's participant events (ADR-0004: Community may not read
 * Admission's own `admission_event_presence`). Rows exist only through the
 * projection, so every case emits the Admission event instead of inventing
 * a row — `hubAdmitted()` (`app-modules/platform/tests/Helpers.php`) is the
 * existing process-global fixture for `ParticipantAdmitted`, built to match
 * what `ProjectEventPresence::checkIn()` actually emits; reused here rather
 * than hand-built again.
 *
 * `ParticipantAdmissionReplaced` is not part of this listener's contract
 * (see `ProjectAdmissionBadge`'s docblock): access is unchanged by
 * definition when it fires, so the last case here proves that emitting it
 * alone, after an admission, leaves the badge exactly as it was.
 */
it('sets checked_in true for an admitted participant', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $userId = (string) Str::ulid();
    $eventId = (string) Str::ulid();

    event(hubAdmitted($brand->getKey(), $userId, $eventId));

    $presence = EventPresence::withoutBrandScope()
        ->where('brand_id', $brand->getKey())
        ->where('event_id', $eventId)
        ->where('user_id', $userId)
        ->firstOrFail();

    expect($presence->checked_in)->toBeTrue();
});

it('flips last_direction on an out scan and leaves checked_in alone', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $userId = (string) Str::ulid();
    $eventId = (string) Str::ulid();

    event(hubAdmitted($brand->getKey(), $userId, $eventId));
    event(new ParticipantPresenceChanged($userId, $eventId, $brand->getKey(), 'in', now()->toIso8601String()));

    event(new ParticipantPresenceChanged($userId, $eventId, $brand->getKey(), 'out', now()->addMinute()->toIso8601String()));

    $presence = EventPresence::withoutBrandScope()
        ->where('brand_id', $brand->getKey())
        ->where('event_id', $eventId)
        ->where('user_id', $userId)
        ->firstOrFail();

    expect($presence->last_direction)->toBe('out')
        ->and($presence->checked_in)->toBeTrue();
});

it('clears the badge on ParticipantHubAccessEnded', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $userId = (string) Str::ulid();
    $eventId = (string) Str::ulid();

    event(hubAdmitted($brand->getKey(), $userId, $eventId));

    event(new ParticipantHubAccessEnded($userId, $eventId, $brand->getKey(), 'concluded', now()->toIso8601String()));

    expect(
        EventPresence::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->where('event_id', $eventId)
            ->where('user_id', $userId)
            ->exists()
    )->toBeFalse();
});

it('is idempotent replaying ParticipantAdmitted', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $userId = (string) Str::ulid();
    $eventId = (string) Str::ulid();
    $admitted = hubAdmitted($brand->getKey(), $userId, $eventId);

    event($admitted);
    event($admitted);

    $rows = EventPresence::withoutBrandScope()
        ->where('brand_id', $brand->getKey())
        ->where('event_id', $eventId)
        ->where('user_id', $userId)
        ->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->checked_in)->toBeTrue();
});

it('is idempotent replaying ParticipantPresenceChanged', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $userId = (string) Str::ulid();
    $eventId = (string) Str::ulid();

    event(hubAdmitted($brand->getKey(), $userId, $eventId));

    $changed = new ParticipantPresenceChanged($userId, $eventId, $brand->getKey(), 'out', now()->toIso8601String());
    event($changed);
    event($changed);

    $rows = EventPresence::withoutBrandScope()
        ->where('brand_id', $brand->getKey())
        ->where('event_id', $eventId)
        ->where('user_id', $userId)
        ->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->last_direction)->toBe('out')
        ->and($rows->first()->checked_in)->toBeTrue();
});

it('is idempotent replaying ParticipantHubAccessEnded', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $userId = (string) Str::ulid();
    $eventId = (string) Str::ulid();

    event(hubAdmitted($brand->getKey(), $userId, $eventId));

    $ended = new ParticipantHubAccessEnded($userId, $eventId, $brand->getKey(), 'revoked', now()->toIso8601String());
    event($ended);
    event($ended);

    expect(
        EventPresence::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->where('event_id', $eventId)
            ->where('user_id', $userId)
            ->exists()
    )->toBeFalse();
});

it('short-circuits silently when the module is off for the event brand', function (): void {
    $brand = $this->withBrand();

    $userId = (string) Str::ulid();
    $eventId = (string) Str::ulid();

    event(hubAdmitted($brand->getKey(), $userId, $eventId));

    expect(
        EventPresence::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->where('event_id', $eventId)
            ->where('user_id', $userId)
            ->exists()
    )->toBeFalse();
});

it('does not react to ParticipantAdmissionReplaced', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $userId = (string) Str::ulid();
    $eventId = (string) Str::ulid();

    event(hubAdmitted($brand->getKey(), $userId, $eventId));

    event(new ParticipantAdmissionReplaced(
        userId: $userId,
        eventId: $eventId,
        brandId: $brand->getKey(),
        admissionId: (string) Str::ulid(),
        replacedAt: now()->toIso8601String(),
    ));

    $presence = EventPresence::withoutBrandScope()
        ->where('brand_id', $brand->getKey())
        ->where('event_id', $eventId)
        ->where('user_id', $userId)
        ->firstOrFail();

    // Unaffected: no listener consumes this event, and the badge is exactly
    // what ParticipantAdmitted left it — see ProjectAdmissionBadge's
    // docblock for why this event carries nothing the badge needs.
    expect($presence->checked_in)->toBeTrue()
        ->and($presence->last_direction)->toBeNull();
});

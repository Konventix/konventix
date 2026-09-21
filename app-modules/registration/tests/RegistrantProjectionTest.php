<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Registration\Models\Registrant;

/**
 * `registration_registrants` (SPEC.md "Consumes" — Identity's events),
 * since ADR-0064 §6 also the two facts the attendee list needs: the handle
 * and the opt-in. Helpers prefixed `registrantProjection*` — Pest helpers
 * are process-global (.ai/rules/tests.md).
 */
function registrantProjectionProfileUpdated(string $userId, string $username, bool $listed, string $displayName = 'Ada Lovelace'): UserProfileUpdated
{
    return new UserProfileUpdated(
        userId: $userId,
        email: $username.'@example.test',
        displayName: $displayName,
        username: $username,
        tagline: null,
        statusEmoji: null,
        socialLinks: [],
        visibility: 'internal',
        listedInAttendees: $listed,
        locale: 'en',
        updatedAt: now()->toIso8601String(),
    );
}

it('projects the handle from UserRegistered and starts unlisted', function (): void {
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

    $registrant = Registrant::query()->findOrFail($userId);

    expect($registrant->username)->toBe('ada-lovelace')
        ->and($registrant->listed)->toBeFalse()
        ->and($registrant->display_name)->toBe('Ada Lovelace');
});

it('projects the opt-in and the handle from UserProfileUpdated, both ways', function (): void {
    $userId = (string) Str::ulid();

    event(registrantProjectionProfileUpdated($userId, 'ada', listed: true));

    $registrant = Registrant::query()->findOrFail($userId);
    expect($registrant->username)->toBe('ada')
        ->and($registrant->listed)->toBeTrue();

    event(registrantProjectionProfileUpdated($userId, 'countess', listed: false, displayName: 'Countess of Lovelace'));

    $registrant->refresh();
    expect($registrant->username)->toBe('countess')
        ->and($registrant->listed)->toBeFalse()
        ->and($registrant->display_name)->toBe('Countess of Lovelace');
});

it('leaves the opt-in alone when UserRegistered replays after the profile event', function (): void {
    $brand = $this->withBrand();
    $userId = (string) Str::ulid();

    event(registrantProjectionProfileUpdated($userId, 'ada', listed: true));
    event(new UserRegistered(
        userId: $userId,
        brandId: $brand->getKey(),
        email: 'ada@example.test',
        displayName: 'Ada Lovelace',
        username: 'ada',
        locale: 'en',
        registeredAt: now()->toIso8601String(),
    ));

    expect(Registrant::query()->findOrFail($userId)->listed)->toBeTrue();
});

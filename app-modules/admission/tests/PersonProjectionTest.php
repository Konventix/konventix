<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Models\Person;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;

/**
 * Admission's own copy of a person's display name (design doc §13,
 * 2026-09-11 amendment "the holder-name fallback drops the billing
 * recipient"): the ticket's holder band needs a name, and Admission may
 * not read Registration's `billing_recipient_name` nor Identity's `User`
 * model (ADR-0004) — so it keeps its own read model, mirroring
 * Registration's `ProjectRegistrant` and Messaging's `ProjectRecipient`.
 */
function admissionPersonRegisteredEvent(
    string $userId,
    string $brandId,
    string $displayName = 'Ada Lovelace',
    string $email = 'ada@example.test',
): UserRegistered {
    return new UserRegistered(
        userId: $userId,
        brandId: $brandId,
        email: $email,
        displayName: $displayName,
        username: Str::slug($displayName),
        locale: 'de',
        registeredAt: now()->toIso8601String(),
    );
}

it('projects UserRegistered into the person read model, idempotently', function (): void {
    $brand = $this->withBrand();
    $userId = (string) Str::ulid();

    event(admissionPersonRegisteredEvent($userId, $brand->id));
    event(admissionPersonRegisteredEvent($userId, $brand->id));

    $person = Person::query()->findOrFail($userId);

    expect(Person::query()->count())->toBe(1)
        ->and($person->display_name)->toBe('Ada Lovelace')
        // The handle travels too (ADR-0065): the pages print it.
        ->and($person->username)->toBe('ada-lovelace')
        ->and($person->email)->toBe('ada@example.test');
});

it('re-projects UserProfileUpdated over an existing row', function (): void {
    $brand = $this->withBrand();
    $userId = (string) Str::ulid();

    event(admissionPersonRegisteredEvent($userId, $brand->id));

    event(new UserProfileUpdated(
        userId: $userId,
        email: 'countess@example.test',
        displayName: 'Countess of Lovelace',
        username: 'countess-lovelace',
        tagline: null,
        statusEmoji: null,
        socialLinks: [],
        visibility: 'internal',
        listedInAttendees: false,
        locale: 'en',
        updatedAt: now()->toIso8601String(),
    ));

    $person = Person::query()->findOrFail($userId);

    expect($person->display_name)->toBe('Countess of Lovelace')
        ->and($person->username)->toBe('countess-lovelace')
        ->and($person->email)->toBe('countess@example.test');
});

it('is not brand-scoped: a person projected under one brand is still found under another', function (): void {
    $firstBrand = $this->withBrand();
    $userId = (string) Str::ulid();

    event(admissionPersonRegisteredEvent($userId, $firstBrand->id));

    // A second, unrelated brand context — Person carries no BelongsToBrand
    // scope (ADR-0016: accounts are operator-global), so the row is still
    // reachable without withoutBrandScope(). Registrant and Recipient hold
    // the same shape for the same reason.
    $this->withBrand();

    expect(Person::query()->find($userId))->not->toBeNull();
});

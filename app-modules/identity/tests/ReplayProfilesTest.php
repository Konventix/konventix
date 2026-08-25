<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Konventix\Content\Models\Author;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Models\Recipient;

/**
 * `identity:replay-profiles` re-emits UserProfileUpdated for every account so
 * the read models other modules keep (Content authors, Messaging recipients,
 * Registration registrants) can be rebuilt from the contract — which is what
 * ADR-0004 promises and what nothing delivered until now.
 */
it('emits one UserProfileUpdated per account carrying the current values', function (): void {
    $this->withBrand();
    $ada = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'locale' => 'de']);
    $grace = User::factory()->create(['name' => 'Grace Hopper']);

    Event::fake([UserProfileUpdated::class]);

    $this->artisan('identity:replay-profiles')
        ->expectsOutputToContain('2')
        ->assertSuccessful();

    Event::assertDispatchedTimes(UserProfileUpdated::class, 2);
    Event::assertDispatched(UserProfileUpdated::class, fn (UserProfileUpdated $event): bool => $event->userId === $ada->getKey()
        && $event->email === 'ada@example.test'
        && $event->displayName === 'Ada Lovelace'
        && $event->locale === 'de');
    Event::assertDispatched(UserProfileUpdated::class, fn (UserProfileUpdated $event): bool => $event->userId === $grace->getKey());
});

it('rebuilds the read models of accounts the projections never saw', function (): void {
    $this->withBrand();
    $user = User::factory()->create(['name' => 'Ada Lovelace']);

    // The rows a pre-projection account lacks — deleted here to simulate one.
    Author::query()->whereKey($user->getKey())->delete();
    Recipient::query()->whereKey($user->getKey())->delete();

    expect(Author::query()->find($user->getKey()))->toBeNull()
        ->and(Recipient::query()->find($user->getKey()))->toBeNull();

    $this->artisan('identity:replay-profiles')->assertSuccessful();

    expect(Author::query()->findOrFail($user->getKey())->display_name)->toBe('Ada Lovelace')
        ->and(Recipient::query()->findOrFail($user->getKey())->display_name)->toBe('Ada Lovelace');
});

it('walks the table in chunks so a large operator does not load every account at once', function (): void {
    $this->withBrand();
    User::factory()->count(3)->create();

    Event::fake([UserProfileUpdated::class]);

    $this->artisan('identity:replay-profiles', ['--chunk' => 2])->assertSuccessful();

    Event::assertDispatchedTimes(UserProfileUpdated::class, 3);
});

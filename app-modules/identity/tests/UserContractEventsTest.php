<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Konventix\Identity\Actions\CreateNewUser;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\User;

/**
 * Identity's contract events (SPEC.md "Public contract"): the scalar
 * payloads read-model consumers (Messaging recipients, future
 * registrant/holder models) project from (ADR-0004).
 */
it('emits UserRegistered with scalar account data when an account is created', function (): void {
    $brand = $this->withBrand();

    Event::fake([UserRegistered::class]);

    $user = app(CreateNewUser::class)->create([
        'name' => 'Ada Lovelace',
        'username' => 'ada-lovelace',
        'email' => 'ada@example.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    Event::assertDispatched(UserRegistered::class, fn (UserRegistered $event): bool => $event->userId === $user->id
        && $event->brandId === $brand->getKey()
        && $event->email === 'ada@example.test'
        && $event->displayName === 'Ada Lovelace'
        && $event->locale === $user->locale);
});

it('emits UserRegistered with the account\'s username', function (): void {
    $this->withBrand();

    Event::fake([UserRegistered::class]);

    $user = app(CreateNewUser::class)->create([
        'name' => 'Ada Lovelace',
        'username' => 'ada-lovelace',
        'email' => 'ada@example.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    Event::assertDispatched(UserRegistered::class, fn (UserRegistered $event): bool => $event->userId === $user->id
        && $event->username === $user->username);
});

it('emits UserProfileUpdated with the current values on a profile update', function (): void {
    $this->withBrand();

    $user = User::factory()->create();

    Event::fake([UserProfileUpdated::class]);

    $this->actingAs($user)
        ->patch('/settings/profile', [
            'name' => 'Countess of Lovelace',
            'email' => 'countess@example.test',
            'username' => $user->username,
            'visibility' => 'internal',
            'timezone' => 'Europe/Berlin',
        ])
        ->assertRedirect();

    Event::assertDispatched(UserProfileUpdated::class, fn (UserProfileUpdated $event): bool => $event->userId === $user->id
        && $event->email === 'countess@example.test'
        && $event->displayName === 'Countess of Lovelace'
        && $event->username === $user->username
        && $event->tagline === null
        && $event->socialLinks === []
        && $event->visibility === 'internal');
});

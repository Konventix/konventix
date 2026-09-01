<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Identity\Enums\ProfileVisibility;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Gdpr\IdentityGdprHandler;
use Konventix\Identity\Models\User;

/**
 * ADR-0052: a profile chooses its audience. `profile_visibility` joins the
 * profile-of-record on `users`, defaults to `internal` (ADR-0051 §7's
 * members-only rule, unchanged), and travels in `UserProfileUpdated` next to
 * every other profile field the event already carries.
 */
it('defaults a fresh user to internal visibility', function (): void {
    $user = User::factory()->create();

    expect($user->profile_visibility)->toBe(ProfileVisibility::Internal);
});

it('persists a chosen visibility on the settings form and carries it on the widened event', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    Event::fake([UserProfileUpdated::class]);

    $this->actingAs($user)
        ->patch('/settings/profile', [
            'name' => $user->name, 'email' => $user->email,
            'username' => $user->username,
            'visibility' => 'public',
        ])
        ->assertRedirect();

    $user->refresh();

    expect($user->profile_visibility)->toBe(ProfileVisibility::Public);

    Event::assertDispatched(UserProfileUpdated::class, fn (UserProfileUpdated $event): bool => $event->userId === $user->id
        && $event->visibility === 'public');
});

it('refuses a visibility value outside the enum', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/profile', [
            'name' => $user->name, 'email' => $user->email,
            'username' => $user->username,
            'visibility' => 'friends-only',
        ])
        ->assertInvalid(['visibility']);
});

it('resets visibility to internal on anonymization', function (): void {
    $user = User::factory()->create(['profile_visibility' => ProfileVisibility::Public]);

    app(IdentityGdprHandler::class)->delete(SubjectRef::forUser($user->getKey()), DeletionMode::Anonymize);

    $user->refresh();

    expect($user->profile_visibility)->toBe(ProfileVisibility::Internal);
});

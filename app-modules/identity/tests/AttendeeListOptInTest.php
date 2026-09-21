<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Gdpr\IdentityGdprHandler;
use Konventix\Identity\Models\User;

/**
 * ADR-0064 §1: the opt-in to be named on an event's attendee list is a
 * choice of its own on the profile-of-record — default off, separate from
 * `profile_visibility`, carried on `UserProfileUpdated` so Registration's
 * registrant read model can learn it without reading `users`.
 */
it('defaults a fresh user to not listed', function (): void {
    $user = User::factory()->create();

    expect($user->listed_in_attendees)->toBeFalse();
});

it('persists the checkbox from the settings form and carries it on the widened event', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    Event::fake([UserProfileUpdated::class]);

    // A checked HTML checkbox arrives as the string "1", not a boolean.
    $this->actingAs($user)
        ->patch('/settings/profile', [
            'name' => $user->name, 'email' => $user->email,
            'username' => $user->username,
            'visibility' => 'internal',
            'listedInAttendees' => '1',
        ])
        ->assertRedirect();

    expect($user->refresh()->listed_in_attendees)->toBeTrue();

    Event::assertDispatched(UserProfileUpdated::class, fn (UserProfileUpdated $event): bool => $event->userId === $user->id
        && $event->listedInAttendees === true);
});

it('reads an absent checkbox as opting out again', function (): void {
    $this->withBrand();
    $user = User::factory()->create(['listed_in_attendees' => true]);

    Event::fake([UserProfileUpdated::class]);

    $this->actingAs($user)
        ->patch('/settings/profile', [
            'name' => $user->name, 'email' => $user->email,
            'username' => $user->username,
            'visibility' => 'internal',
        ])
        ->assertRedirect();

    expect($user->refresh()->listed_in_attendees)->toBeFalse();

    Event::assertDispatched(UserProfileUpdated::class, fn (UserProfileUpdated $event): bool => $event->userId === $user->id
        && $event->listedInAttendees === false);
});

it('shows the current choice on the settings page', function (): void {
    $this->withBrand();
    $user = User::factory()->create(['listed_in_attendees' => true]);

    $this->actingAs($user)
        ->get('/settings/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('profile.listedInAttendees', true));
});

it('resets the opt-in on anonymization', function (): void {
    $user = User::factory()->create(['listed_in_attendees' => true]);

    app(IdentityGdprHandler::class)->delete(SubjectRef::forUser($user->getKey()), DeletionMode::Anonymize);

    expect($user->refresh()->listed_in_attendees)->toBeFalse();
});

it('replays the opt-in so a registrant read model can learn it', function (): void {
    $listed = User::factory()->create(['listed_in_attendees' => true]);
    $quiet = User::factory()->create();

    Event::fake([UserProfileUpdated::class]);

    $this->artisan('identity:replay-profiles')->assertSuccessful();

    Event::assertDispatched(UserProfileUpdated::class, fn (UserProfileUpdated $event): bool => $event->userId === $listed->getKey()
        && $event->listedInAttendees === true);
    Event::assertDispatched(UserProfileUpdated::class, fn (UserProfileUpdated $event): bool => $event->userId === $quiet->getKey()
        && $event->listedInAttendees === false);
});

<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;

/**
 * The reader's own zone (#243).
 *
 * `users.timezone` has existed since the account fields migration but was
 * only reachable through the admin mask, and nothing on the display path read
 * it: `formatDateTime()` asked the browser. A person whose laptop travels
 * therefore saw a different "Placed" column in every airport lounge. The
 * column becomes self-service here and rides along on every response as a
 * shared prop, so a page needs no change of its own to honour it.
 */
function validTimezoneProfilePayload(User $user, array $overrides = []): array
{
    return array_merge([
        'name' => $user->name,
        'email' => $user->email,
        'username' => 'ada-lovelace',
        'tagline' => null,
        'statusEmoji' => null,
        'socialLinks' => [],
        'visibility' => 'internal',
        'timezone' => 'Europe/Berlin',
    ], $overrides);
}

it('offers the person their own timezone on the profile settings page', function (): void {
    $this->withBrand();
    $user = User::factory()->create(['timezone' => 'America/New_York']);

    $this->actingAs($user)
        ->get('/settings/profile')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('settings/Profile')
            ->where('profile.timezone', 'America/New_York'));
});

it('lets the person set their own timezone without an administrator', function (): void {
    $this->withBrand();
    $user = User::factory()->create(['timezone' => 'UTC']);

    $this->actingAs($user)
        ->patch('/settings/profile', validTimezoneProfilePayload($user, ['timezone' => 'America/New_York']))
        ->assertRedirect();

    expect($user->refresh()->timezone)->toBe('America/New_York');
});

it('refuses a timezone that is not an IANA zone', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/profile', validTimezoneProfilePayload($user, ['timezone' => 'Mars/Olympus_Mons']))
        ->assertInvalid(['timezone']);
});

it('shares the reader’s zone with every page, so no page has to ask', function (): void {
    $this->withBrand();
    $user = User::factory()->create(['timezone' => 'America/New_York']);

    $this->actingAs($user)
        ->get('/settings/profile')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.user.timezone', 'America/New_York'));
});

it('shares no zone for a guest, and the browser’s own zone answers instead', function (): void {
    $this->withBrand();

    $this->get('/')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.user', null));
});

/*
|--------------------------------------------------------------------------
| "Never chose one" has to be a state the column can hold
|--------------------------------------------------------------------------
|
| Review finding 1: the column arrived `NOT NULL DEFAULT 'UTC'`, so every
| account that had never opened the settings page counted as having chosen
| UTC — and the browser fallback, which is the whole point of the fallback,
| could only ever fire for a guest. A signed-in operator in Berlin read the
| console on their own clock before #243 and in UTC after it, two hours out in
| summer, until they found a select nothing pointed them at.
|
| Null is that state now, and it is what a fresh account has.
|
*/

it('starts an account with no zone of its own, so the browser answers', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    expect($user->timezone)->toBeNull();

    $this->actingAs($user)
        ->get('/settings/profile')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('profile.timezone', null)
            ->where('auth.user.timezone', null));
});

it('lets the person hand the choice back to their browser', function (): void {
    $this->withBrand();
    $user = User::factory()->create(['timezone' => 'America/New_York']);

    $this->actingAs($user)
        ->patch('/settings/profile', validTimezoneProfilePayload($user, ['timezone' => '']))
        ->assertRedirect();

    expect($user->refresh()->timezone)->toBeNull();
});

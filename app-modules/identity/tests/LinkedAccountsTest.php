<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Konventix\Identity\Events\SocialIdentityUnlinked;
use Konventix\Identity\Models\SocialIdentity;
use Konventix\Identity\Models\User;

/**
 * The settings screen for the ways into an account that are not a password —
 * and the guard that stops somebody removing the last one.
 */
it('lists every registered provider, linked or not', function (): void {
    $this->withBrand();

    $user = User::factory()->create(['password' => Hash::make('secret-password')]);

    $this->actingAs($user)
        ->get('http://localhost/settings/linked-accounts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/LinkedAccounts')
            ->where('accounts.0.provider', 'steam')
            ->where('accounts.0.label', 'Steam')
            ->where('accounts.0.linked', false)
            ->where('hasPassword', true));
});

it('shows a linked provider with when it was connected', function (): void {
    $this->withBrand();

    $user = User::factory()->create(['password' => Hash::make('secret-password')]);
    SocialIdentity::factory()->for($user)->create(['provider' => 'steam', 'nickname' => 'gaben']);

    $this->actingAs($user)
        ->get('http://localhost/settings/linked-accounts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('accounts.0.linked', true)
            ->where('accounts.0.nickname', 'gaben')
            ->where('accounts.0.canUnlink', true));
});

it('disconnects a provider when the account has a password to fall back on', function (): void {
    $this->withBrand();
    Event::fake([SocialIdentityUnlinked::class]);

    $user = User::factory()->create(['password' => Hash::make('secret-password')]);
    SocialIdentity::factory()->for($user)->create(['provider' => 'steam']);

    $this->actingAs($user)
        ->delete('http://localhost/settings/linked-accounts/steam')
        ->assertRedirect();

    expect($user->socialIdentities()->count())->toBe(0);

    Event::assertDispatched(SocialIdentityUnlinked::class);
});

/**
 * The guard this Action exists for. Password reset delivers to an address the
 * person can no longer prove they own by signing in, so an account that loses
 * its only login is lost permanently — not merely inconvenienced.
 */
it('refuses to remove the only way into an account', function (): void {
    $this->withBrand();

    $user = User::factory()->create(['password' => null]);
    SocialIdentity::factory()->for($user)->create(['provider' => 'steam']);

    $this->actingAs($user)
        ->delete('http://localhost/settings/linked-accounts/steam')
        ->assertSessionHasErrors('provider');

    expect($user->socialIdentities()->count())->toBe(1);
});

it('tells the page the only way in cannot be removed', function (): void {
    $this->withBrand();

    $user = User::factory()->create(['password' => null]);
    SocialIdentity::factory()->for($user)->create(['provider' => 'steam']);

    $this->actingAs($user)
        ->get('http://localhost/settings/linked-accounts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('accounts.0.linked', true)
            ->where('accounts.0.canUnlink', false)
            ->where('hasPassword', false));
});

/**
 * The rule is "no way in left", not "no password" — a passwordless account
 * holding two identities may drop one.
 */
it('allows a passwordless account holding two identities to drop one', function (): void {
    $this->withBrand();

    $user = User::factory()->create(['password' => null]);
    SocialIdentity::factory()->for($user)->create(['provider' => 'steam']);
    SocialIdentity::factory()->for($user)->create(['provider' => 'imaginary']);

    $this->actingAs($user)
        ->delete('http://localhost/settings/linked-accounts/steam')
        ->assertRedirect();

    expect($user->socialIdentities()->count())->toBe(1);
});

it('does not expose the provider identifier to the settings page', function (): void {
    $this->withBrand();

    $user = User::factory()->create(['password' => Hash::make('secret-password')]);
    SocialIdentity::factory()->for($user)->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198000000777',
    ]);

    $response = $this->actingAs($user)->get('http://localhost/settings/linked-accounts');

    // A SteamID64 identifies a natural person. The page has no use for it, so
    // it does not travel — it belongs in the GDPR export instead.
    expect($response->getContent())->not->toContain('76561198000000777');
});

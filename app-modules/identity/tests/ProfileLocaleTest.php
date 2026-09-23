<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;

/**
 * The person's own language (ADR-0068 §4): the top of the chain, or nothing —
 * which is not a missing value but a choice, "speak whatever this site
 * speaks", so the select offers it as "Default" and the column holds null.
 */
function localeProfilePayload(User $user, array $overrides = []): array
{
    return array_merge([
        'name' => $user->name,
        'email' => $user->email,
        'username' => 'ada-locale',
        'tagline' => null,
        'statusEmoji' => null,
        'socialLinks' => [],
        'visibility' => 'internal',
        'timezone' => null,
        'locale' => 'de',
    ], $overrides);
}

it('offers the person their own language on the profile settings page', function (): void {
    $this->withBrand();
    $user = User::factory()->create(['locale' => 'de']);

    $this->actingAs($user)
        ->get('/settings/profile')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('settings/Profile')
            ->where('profile.locale', 'de'));
});

it('lets the person choose their own language', function (): void {
    $this->withBrand();
    $user = User::factory()->create(['locale' => null]);

    $this->actingAs($user)
        ->patch('/settings/profile', localeProfilePayload($user))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($user->refresh()->locale)->toBe('de');
});

it('lets the person hand the choice back to the site', function (): void {
    $this->withBrand();
    $user = User::factory()->create(['locale' => 'de']);

    $this->actingAs($user)
        ->patch('/settings/profile', localeProfilePayload($user, ['locale' => '']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($user->refresh()->locale)->toBeNull();
});

it('refuses a language the instance does not ship', function (): void {
    $this->withBrand();
    $user = User::factory()->create(['locale' => 'de']);

    $this->actingAs($user)
        ->patch('/settings/profile', localeProfilePayload($user, ['locale' => 'fr']))
        ->assertInvalid(['locale']);

    expect($user->refresh()->locale)->toBe('de');
});

it('re-renders in the chosen language after the save', function (): void {
    $this->withBrand();
    $user = User::factory()->create(['locale' => null]);

    $this->actingAs($user)
        ->patch('/settings/profile', localeProfilePayload($user))
        ->assertRedirect();

    $this->actingAs($user->refresh())
        ->get('/settings/profile')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('locale', 'de'));
});

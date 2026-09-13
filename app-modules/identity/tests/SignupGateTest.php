<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Konventix\Identity\Models\User;
use Laravel\Fortify\Features;

/**
 * Closed signups (hotfix 2026-09-13): the operator's own switch on the
 * console's Operator settings page and the instance cap `SIGNUPS_ENABLED`,
 * combined in `OperatorSettings::signupsOpen()` and enforced by
 * `EnsureSignupsOpen` on both ways in — Fortify's register routes and the
 * social completion step. Existing accounts are untouched: the gate is on
 * account creation only, never on login.
 */
beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::registration());
});

function signupGateSteamPending(): array
{
    return [
        'provider' => 'steam',
        'providerUserId' => '76561198000000901',
        'nickname' => 'closed-door',
        'avatarUrl' => null,
        'email' => null,
    ];
}

function signupGateSubmission(): array
{
    return [
        'name' => 'Late Comer',
        'username' => 'late-comer',
        'email' => 'late@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ];
}

it('sends a visitor from the sign-up page to login when the operator closed signups', function (): void {
    $brand = $this->withBrand();
    $brand->operator->forceFill(['signups_open' => false])->save();

    $this->get(route('register'))
        ->assertRedirect(route('login', absolute: false))
        ->assertSessionHasErrors('email');
});

it('refuses the submission itself while signups are closed, and creates nobody', function (): void {
    $brand = $this->withBrand();
    $brand->operator->forceFill(['signups_open' => false])->save();

    $this->post(route('register.store'), signupGateSubmission())
        ->assertRedirect(route('login', absolute: false));

    $this->assertGuest();
    expect(User::query()->where('email', 'late@example.test')->exists())->toBeFalse();
});

it('closes the social completion step too', function (): void {
    $brand = $this->withBrand();
    $brand->operator->forceFill(['signups_open' => false])->save();

    $this->withSession(['identity.social.pending' => signupGateSteamPending()])
        ->get('http://localhost/auth/steam/complete')
        ->assertRedirect(route('login', absolute: false));

    $this->withSession(['identity.social.pending' => signupGateSteamPending()])
        ->post('http://localhost/auth/steam/complete', [
            'name' => 'Ada Lovelace',
            'username' => 'ada-lovelace',
            'email' => 'ada@example.test',
        ])
        ->assertRedirect(route('login', absolute: false));

    $this->assertGuest();
    expect(User::query()->where('email', 'ada@example.test')->exists())->toBeFalse();
});

it('lets the environment close signups the console left open', function (): void {
    // The cap wins in one direction only: the environment can close what
    // the operator opened, never the reverse.
    $this->withBrand();
    config()->set('platform.signups.enabled', false);

    $this->get(route('register'))
        ->assertRedirect(route('login', absolute: false));

    $this->post(route('register.store'), signupGateSubmission())
        ->assertRedirect(route('login', absolute: false));

    $this->assertGuest();
});

it('keeps the sign-up form and its submission open by default', function (): void {
    Queue::fake();
    $this->withBrand();

    $this->get(route('register'))->assertOk();

    $this->post(route('register.store'), signupGateSubmission())
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

it('drops the sign-up link from the login page while signups are closed', function (): void {
    $brand = $this->withBrand();

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canRegister', true));

    $brand->operator->forceFill(['signups_open' => false])->save();

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canRegister', false));
});

it('never stands between an existing account and its login', function (): void {
    $brand = $this->withBrand();
    $brand->operator->forceFill(['signups_open' => false])->save();
    $user = User::factory()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

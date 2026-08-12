<?php

declare(strict_types=1);

use Konventix\Identity\Database\Factories\UserFactory;
use Konventix\Identity\Models\User;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

/**
 * Enrolment (`Features::twoFactorAuthentication(['confirm' => true,
 * 'confirmPassword' => true])`, config/fortify.php) and the challenge login
 * path (task scope item 6) — both run entirely through Fortify's own routes
 * and controllers (`php artisan route:list` confirms they are registered);
 * this module only supplies the view callbacks and the `User` traits
 * (`TwoFactorAuthenticatable`, the migrated columns).
 */
beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
});

test('a user can enrol in two-factor authentication', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.enable'));

    $response->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull();
    expect($user->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('confirming with a valid code enables two-factor authentication', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.enable'));

    $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.confirm'), ['code' => $code]);

    $response->assertSessionHasNoErrors();
    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

test('confirming with an invalid code does not enable two-factor authentication', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.enable'));

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.confirm'), ['code' => '000000']);

    $response->assertSessionHasErrors();
    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('a user can complete login through the two-factor challenge', function () {
    $user = User::factory()->withTwoFactor()->create();

    $challenge = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $challenge->assertRedirect(route('two-factor.login'));
    $this->assertGuest();

    $code = app(Google2FA::class)->getCurrentOtp(UserFactory::twoFactorSecret());

    $response = $this->post(route('two-factor.login.store'), ['code' => $code]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});

test('a user can complete login with a recovery code', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $recoveryCode = collect($user->recoveryCodes())->first();

    $response = $this->post(route('two-factor.login.store'), ['recovery_code' => $recoveryCode]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});

test('a confirmed user can disable two-factor authentication', function () {
    $user = User::factory()->withTwoFactor()->create();

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('two-factor.disable'));

    $response->assertSessionHasNoErrors();
    expect($user->fresh()->two_factor_secret)->toBeNull();
});

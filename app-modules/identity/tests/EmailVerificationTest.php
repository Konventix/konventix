<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Konventix\Identity\Models\User;
use Laravel\Fortify\Features;

/**
 * The `verified` gate on routes that carry it (task scope item 6: "email
 * verification gate on 'verified' routes") — `security.edit`,
 * `profile.destroy` and the host's own `dashboard` route all already declare
 * `['auth', 'verified']`; before `MustVerifyEmail` was implemented on `User`
 * this middleware was a silent no-op (`instanceof` check failed), so this is
 * the first PR where it actually does anything.
 */
beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

test('an unverified user is redirected away from a verified-only route', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertRedirect(route('verification.notice'));
});

test('a verified user reaches a verified-only route', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
});

test('the verification notice screen renders for an unverified user', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    $response->assertOk();
});

test('email can be verified via the signed link', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('email is not verified with an invalid hash', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')],
    );

    $this->actingAs($user)->get($verificationUrl);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('the verification email can be resent', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post(route('verification.send'));

    Notification::assertSentTo($user, VerifyEmail::class);
});

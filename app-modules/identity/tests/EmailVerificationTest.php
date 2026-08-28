<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Jobs\SendQueuedMessage;
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

    // Past the gate: the dashboard route itself now forwards to the brand
    // landing page (brand hosts have no dashboard) — what matters here is
    // that verification did NOT bounce us to the notice screen.
    $response->assertRedirect('/');
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
    $this->withBrand();
    Queue::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post(route('verification.send'));

    Queue::assertPushedOn(
        'messaging',
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'identity.verify-email'
            && $job->message->recipientId === $user->getKey(),
    );
});

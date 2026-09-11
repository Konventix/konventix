<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
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

/**
 * #81: they are identifiable today (`email_verified_at` is null) on every
 * ordinary page, not only the handful wrapped in `verified` — the `dashboard`
 * route redirects to `verification.notice` on the way in, but a Steam
 * signup can browse the shop, the profile settings and everything else
 * without ever passing through it. This prop is the shared, cheap flag
 * `EmailVerificationBanner.vue` reads to prompt on every page instead.
 *
 * Deliberately NOT folded into the `auth.user` shared prop: SPEC.md fixes
 * that shape at five fields (ULID, display name, avatar URL, locale,
 * timezone) precisely so nothing has to ask "does this belong there" again.
 */
test('the shared emailVerified prop reflects the signed-in account, and is null for a guest', function () {
    $this->withBrand();

    $verified = User::factory()->create();
    $this->actingAs($verified)
        ->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('emailVerified', true));

    auth()->logout();

    $unverified = User::factory()->unverified()->create();
    $this->actingAs($unverified)
        ->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('emailVerified', false));

    auth()->logout();

    $this->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('emailVerified', null));
});

/**
 * Asserted against the SSR-rendered page (`.ai/rules/tests.md` /
 * `SettingsLayoutTest`'s precedent) because the banner is markup in
 * `AppTopbarLayout.vue`, not a prop this or any other Inertia assertion
 * would see. Split into two tests rather than one sequential two-user
 * request — a second `get()` in the same test read the first request's
 * SSR-rendered markup, which the passing prop-only test above rules out as
 * a backend bug (`emailVerified` was correct on both requests every time);
 * this is a rendering artefact of this environment's SSR process, not
 * something to paper over with a shared test.
 */
test('the brand shell prompts an unverified account to confirm its address', function () {
    $this->withBrand();

    $unverified = User::factory()->unverified()->create();

    $this->actingAs($unverified)
        ->get('/')
        ->assertOk()
        ->assertSee('confirm your email address', escape: false);
});

test('the brand shell stays quiet for a verified account', function () {
    $this->withBrand();

    $verified = User::factory()->create();

    $this->actingAs($verified)
        ->get('/')
        ->assertOk()
        ->assertDontSee('confirm your email address', escape: false);
});

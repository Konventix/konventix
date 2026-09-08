<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;
use Konventix\Identity\Models\User;

/**
 * #253: four authentication routes arrived with no throttle at all.
 *
 * The two-factor challenge is the sharp one — Fortify reads
 * `config('fortify.limiters.two-factor')` and `array_filter()`s the middleware
 * away when it is null, so a six-digit TOTP code and the recovery codes could
 * be tried without limit by anyone already holding valid credentials. The
 * other three (registration, forgot-password, reset-password) allow user
 * enumeration, reset-mail flooding and bulk account creation.
 *
 * Fortify offers no configuration hook for those three, so IdentityServiceProvider
 * attaches its own named limiters to the routes Fortify registered.
 */
beforeEach(function (): void {
    $this->withBrand();
});

/**
 * The whole finding was a null in the config: `array_filter()` drops the
 * middleware silently, so the route reads as configured and is not.
 */
it('names a two-factor limiter fortify can actually find', function (): void {
    expect(config('fortify.limiters.two-factor'))->toBe('two-factor')
        ->and(RateLimiter::limiter('two-factor'))->not->toBeNull();
});

/**
 * Nothing here depends on knowing a code, which is the point: the throttle,
 * not the code, is what has to stop the sixth attempt.
 *
 * `withSession(['login.id' => …])` is how a challenge in progress actually
 * looks — Fortify's RedirectIfTwoFactorAuthenticatable writes that key after
 * the password step — and it is also what the limiter is keyed on. The test
 * client starts a fresh session per request, so without it every attempt
 * would land in its own bucket here while a real browser's would not.
 */
it('throttles the two-factor challenge without ever needing a valid code', function (): void {
    $user = User::factory()->create();

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        expect($this->withSession(['login.id' => $user->getKey()])
            ->post('/two-factor-challenge', ['code' => '000000'])
            ->getStatusCode())->not->toBe(429);
    }

    $this->withSession(['login.id' => $user->getKey()])
        ->post('/two-factor-challenge', ['code' => '000000'])
        ->assertStatus(429);
});

it('throttles registration', function (): void {
    for ($attempt = 1; $attempt <= 6; $attempt++) {
        expect($this->post('/register', ['email' => 'someone@konventix.test'])->getStatusCode())
            ->not->toBe(429);
    }

    $this->post('/register', ['email' => 'someone@konventix.test'])->assertStatus(429);
});

it('throttles the forgot-password request', function (): void {
    User::factory()->create(['email' => 'someone@konventix.test']);

    for ($attempt = 1; $attempt <= 6; $attempt++) {
        expect($this->post('/forgot-password', ['email' => 'someone@konventix.test'])->getStatusCode())
            ->not->toBe(429);
    }

    $this->post('/forgot-password', ['email' => 'someone@konventix.test'])->assertStatus(429);
});

it('throttles the password reset', function (): void {
    for ($attempt = 1; $attempt <= 6; $attempt++) {
        expect($this->post('/reset-password', ['email' => 'someone@konventix.test'])->getStatusCode())
            ->not->toBe(429);
    }

    $this->post('/reset-password', ['email' => 'someone@konventix.test'])->assertStatus(429);
});

/**
 * Per IP is not enough on its own: a reset-mail flood aimed at one inbox
 * arrives from wherever the attacker likes. The second limit is keyed on the
 * address in the request, so the victim's budget is spent whichever IP asks.
 */
it('throttles a reset flood aimed at one address from many addresses of origin', function (): void {
    User::factory()->create(['email' => 'victim@konventix.test']);

    for ($attempt = 1; $attempt <= 6; $attempt++) {
        expect($this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$attempt])
            ->post('/forgot-password', ['email' => 'victim@konventix.test'])
            ->getStatusCode())->not->toBe(429);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->post('/forgot-password', ['email' => 'victim@konventix.test'])
        ->assertStatus(429);
});

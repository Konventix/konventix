<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;
use Konventix\Identity\Models\Session;
use Konventix\Identity\Models\User;

/**
 * The session list + revocation on the Security settings page (task scope
 * item 4). `SESSION_DRIVER=array` in phpunit.xml (unlike production's
 * database driver), so the framework never writes a `sessions` row for the
 * test request itself — these tests insert rows directly, the same shape
 * `Illuminate\Session\DatabaseSessionHandler` would write.
 *
 * "This device" needs the page's request to carry a known session id. The
 * test client keeps no cookie jar, and a request without a session cookie is
 * handed a fresh id, so the id is read back from the cookie a first request
 * sets and replayed with `withCookie()` on the request under test.
 */
function makeSessionRow(string $id, User $user, string $userAgent = 'Other Browser', ?int $lastActivity = null): Session
{
    return Session::create([
        'id' => $id,
        'user_id' => $user->getKey(),
        'ip_address' => '10.0.0.1',
        'user_agent' => $userAgent,
        'payload' => base64_encode(serialize([])),
        'last_activity' => $lastActivity ?? now()->subHour()->timestamp,
    ]);
}

/**
 * Establish a session for `$user` and hand back the cookie name and the
 * session id the response set, for the next request to replay. The name is
 * the store's own (`session()->getName()`), not `config('session.cookie')`:
 * `ResolveBrand` renames the config per brand after the store was built.
 *
 * @return array{string, string}
 */
function establishSessionCookie(object $test, User $user): array
{
    $response = $test->actingAs($user)->get(route('security.edit'));
    $cookie = session()->getName();

    return [$cookie, $response->getCookie($cookie)->getValue()];
}

test('the security page lists the user\'s sessions and marks the current one', function () {
    $user = User::factory()->create();

    [$cookie, $sessionId] = establishSessionCookie($this, $user);
    makeSessionRow($sessionId, $user, 'Test Browser', now()->timestamp);
    makeSessionRow('a-different-session-id', $user);

    $this->actingAs($user)
        ->withCookie($cookie, $sessionId)
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Security')
            ->has('sessions', 2)
            ->where('sessions.0.id', $sessionId)
            ->where('sessions.0.isCurrentDevice', true)
            ->where('sessions.1.isCurrentDevice', false),
        );
});

test('a session the handler would no longer honour is not listed', function () {
    $user = User::factory()->create();

    [$cookie, $sessionId] = establishSessionCookie($this, $user);
    makeSessionRow($sessionId, $user, 'Test Browser', now()->timestamp);
    makeSessionRow('still-active-session-id', $user);
    // One minute past `session.lifetime`: the session handler refuses to read
    // it and garbage collection will delete it, so a "Revoke" button for it
    // would act on nothing.
    makeSessionRow(
        'expired-session-id',
        $user,
        lastActivity: now()->subMinutes((int) config('session.lifetime') + 1)->timestamp,
    );

    $this->actingAs($user)
        ->withCookie($cookie, $sessionId)
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Security')
            ->has('sessions', 2)
            ->where('sessions.0.id', $sessionId)
            ->where('sessions.1.id', 'still-active-session-id'),
        );
});

test('each session carries a readable device line beside the raw user agent', function () {
    $user = User::factory()->create();

    [$cookie, $sessionId] = establishSessionCookie($this, $user);
    makeSessionRow(
        $sessionId,
        $user,
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        now()->timestamp,
    );
    makeSessionRow('a-scanner-session-id', $user, 'python-requests/2.32.3');

    $this->actingAs($user)
        ->withCookie($cookie, $sessionId)
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Security')
            ->where('sessions.0.device', 'Chrome on Windows')
            ->where('sessions.0.isCurrentDevice', true)
            ->where('sessions.0.ipAddress', '10.0.0.1')
            ->where('sessions.1.device', 'Unknown device'),
        );
});

test('the page says whether a session list exists on this installation', function () {
    $user = User::factory()->create();

    // phpunit.xml runs the array driver, on which no row is ever written and
    // the list would be an empty promise; the page is told so.
    $this->actingAs($user)
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Security')
            ->where('sessionsAvailable', false),
        );

    config()->set('session.driver', 'database');

    $this->actingAs($user)
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sessionsAvailable', true),
        );
});

test('a session can be revoked once the password is confirmed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('security.edit'));
    makeSessionRow(session()->getId(), $user, 'Test Browser', now()->timestamp);
    $other = makeSessionRow('revoke-me-session-id', $user);

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('sessions.destroy', ['session' => $other->id]));

    $response->assertRedirect(route('security.edit'));
    expect(Session::query()->whereKey($other->id)->exists())->toBeFalse();
});

test('revoking a session without a confirmed password redirects to confirm password', function () {
    $user = User::factory()->create();
    $other = makeSessionRow('revoke-me-session-id', $user);

    $response = $this->actingAs($user)
        ->delete(route('sessions.destroy', ['session' => $other->id]));

    $response->assertRedirect(route('password.confirm'));
    expect(Session::query()->whereKey($other->id)->exists())->toBeTrue();
});

test('a user cannot revoke another user\'s session', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $strangerSession = makeSessionRow('stranger-session-id', $stranger);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('sessions.destroy', ['session' => $strangerSession->id]));

    expect(Session::query()->whereKey($strangerSession->id)->exists())->toBeTrue();
});

test('every other session can be revoked at once', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    makeSessionRow('other-session-one', $user);
    makeSessionRow('other-session-two', $user);
    makeSessionRow('stranger-session', $stranger);

    $response = $this->actingAs($user)->delete(route('sessions.destroy-others'));

    $response->assertRedirect(route('security.edit'));

    // Every stored session of THIS user is gone (the request's own session id
    // is excluded by the controller, but under the array test driver it never
    // has a row to begin with); other users' sessions are untouched.
    expect(Session::query()->where('user_id', $user->getKey())->count())->toBe(0)
        ->and(Session::query()->whereKey('stranger-session')->exists())->toBeTrue();
});

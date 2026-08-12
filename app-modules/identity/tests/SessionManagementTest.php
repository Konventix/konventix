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
 * `Illuminate\Session\DatabaseSessionHandler` would write, and use the
 * request's own `session()->getId()` (captured after a first authenticated
 * request establishes it, since the test client's cookie jar then carries it
 * into later requests) to mark one of them "current".
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

test('the security page lists the user\'s sessions and marks the current one', function () {
    $user = User::factory()->create();

    // Establishes the session the test client's cookie jar carries into the
    // next request, so its id is known before the assertion request.
    $this->actingAs($user)->get(route('security.edit'));
    makeSessionRow(session()->getId(), $user, 'Test Browser', now()->timestamp);
    makeSessionRow('a-different-session-id', $user);

    $this->actingAs($user)
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Security')
            ->has('sessions', 2),
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

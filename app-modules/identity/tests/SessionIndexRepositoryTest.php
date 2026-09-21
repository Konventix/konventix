<?php

declare(strict_types=1);

use Konventix\Identity\Models\Session;
use Konventix\Identity\Models\User;
use Konventix\Identity\Queries\ActiveSessionsQuery;
use Konventix\Identity\Sessions\CacheSessionIndex;
use Konventix\Identity\Sessions\DatabaseSessionRepository;
use Konventix\Identity\Sessions\NullSessionRepository;
use Konventix\Identity\Sessions\RedisSessionIndexRepository;
use Konventix\Identity\Sessions\SessionRepository;

/**
 * The `Sessions\SessionRepository` contract, driven identically over both
 * stores (D-IDP-17): the `sessions` table and the per-user index
 * `Sessions\IndexedRedisSessionHandler` writes.
 *
 * The index half runs on `CacheSessionIndex` over the array cache store
 * (`CACHE_STORE=array`, phpunit.xml) — that is what the `SessionIndex`
 * interface is for. It keeps this file free of an infrastructure
 * dependency; the real thing is proven once, end to end, in
 * `IndexedRedisSessionHandlerRedisTest`.
 *
 * Helper and dataset names prefixed `identitySessionContract*` — Pest
 * helpers and datasets are process-global (`.ai/rules/tests.md`).
 */
function identitySessionContractRepository(string $store): SessionRepository
{
    return $store === 'database'
        ? new DatabaseSessionRepository(new ActiveSessionsQuery)
        : new RedisSessionIndexRepository(app(CacheSessionIndex::class));
}

function identitySessionContractRecord(
    string $store,
    string $id,
    User $user,
    ?int $lastActivity = null,
    string $userAgent = 'Other Browser',
): void {
    $lastActivity ??= now()->subHour()->timestamp;

    if ($store === 'database') {
        Session::updateOrCreate(['id' => $id], [
            'id' => $id,
            'user_id' => $user->getKey(),
            'ip_address' => '10.0.0.1',
            'user_agent' => $userAgent,
            'payload' => base64_encode(serialize([])),
            'last_activity' => $lastActivity,
        ]);

        return;
    }

    app(CacheSessionIndex::class)->record($id, (string) $user->getKey(), [
        'user_id' => (string) $user->getKey(),
        'ip_address' => '10.0.0.1',
        'user_agent' => $userAgent,
        'last_activity' => $lastActivity,
    ], (int) config('session.lifetime') * 60);
}

dataset('identity session stores', ['database', 'index']);

it('lists a user\'s own sessions, newest activity first', function (string $store) {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    identitySessionContractRecord($store, 'older-session', $user, now()->subHours(2)->timestamp);
    identitySessionContractRecord($store, 'newer-session', $user, now()->subMinutes(5)->timestamp, 'Test Browser');
    identitySessionContractRecord($store, 'stranger-session', $stranger);

    $sessions = identitySessionContractRepository($store)->activeFor($user);

    expect($sessions)->toHaveCount(2)
        ->and($sessions[0]->id)->toBe('newer-session')
        ->and($sessions[0]->userAgent)->toBe('Test Browser')
        ->and($sessions[0]->ipAddress)->toBe('10.0.0.1')
        ->and($sessions[1]->id)->toBe('older-session');
})->with('identity session stores');

it('does not list a session the driver would no longer honour', function (string $store) {
    $user = User::factory()->create();

    identitySessionContractRecord($store, 'live-session', $user, now()->subMinutes(5)->timestamp);
    // One minute past `session.lifetime`: a "Revoke" button for it would act
    // on nothing, so it is not offered.
    identitySessionContractRecord(
        $store,
        'expired-session',
        $user,
        now()->subMinutes((int) config('session.lifetime') + 1)->timestamp,
    );

    $sessions = identitySessionContractRepository($store)->activeFor($user);

    expect($sessions)->toHaveCount(1)
        ->and($sessions[0]->id)->toBe('live-session');
})->with('identity session stores');

it('revokes one session of the acting user and no one else\'s', function (string $store) {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    identitySessionContractRecord($store, 'mine', $user, now()->subMinutes(5)->timestamp);
    identitySessionContractRecord($store, 'theirs', $stranger, now()->subMinutes(5)->timestamp);

    $repository = identitySessionContractRepository($store);

    $repository->revoke($user, 'theirs');
    expect($repository->activeFor($stranger))->toHaveCount(1);

    $repository->revoke($user, 'mine');
    expect($repository->activeFor($user))->toBe([])
        ->and($repository->activeFor($stranger))->toHaveCount(1);
})->with('identity session stores');

it('revokes every session but the one making the request', function (string $store) {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    identitySessionContractRecord($store, 'current', $user, now()->timestamp);
    identitySessionContractRecord($store, 'other-one', $user, now()->subMinutes(5)->timestamp);
    identitySessionContractRecord($store, 'other-two', $user, now()->subMinutes(9)->timestamp);
    identitySessionContractRecord($store, 'stranger-session', $stranger, now()->subMinutes(5)->timestamp);

    $repository = identitySessionContractRepository($store);
    $repository->revokeOthers($user, 'current');

    $remaining = $repository->activeFor($user);

    expect($remaining)->toHaveCount(1)
        ->and($remaining[0]->id)->toBe('current')
        ->and($repository->activeFor($stranger))->toHaveCount(1);
})->with('identity session stores');

it('prunes an indexed id whose meta has expired underneath it', function () {
    $user = User::factory()->create();
    $index = app(CacheSessionIndex::class);

    identitySessionContractRecord('index', 'gone', $user, now()->timestamp);

    // The hash expires on its own TTL while the user's set still names it —
    // the state redis reaches by itself, with no gc and no job.
    cache()->store()->forget('sessions:meta:gone');

    expect($index->sessionIdsFor((string) $user->getKey()))->toContain('gone');

    $sessions = (new RedisSessionIndexRepository($index))->activeFor($user);

    expect($sessions)->toBe([])
        ->and($index->sessionIdsFor((string) $user->getKey()))->not->toContain('gone');
});

it('promises no list while a handler that writes no index is in front of it', function () {
    // `SESSION_DRIVER=array`, so `session()->getHandler()` is Laravel's own
    // ArraySessionHandler: naming the driver "redis" is not enough, the
    // handler has to be the one that fills the index.
    expect((new RedisSessionIndexRepository(app(CacheSessionIndex::class)))->available())->toBeFalse();
});

it('binds the null repository on a driver that stores nothing', function () {
    config()->set('session.driver', 'array');

    $repository = app(SessionRepository::class);

    expect($repository)->toBeInstanceOf(NullSessionRepository::class)
        ->and($repository->available())->toBeFalse()
        ->and($repository->activeFor(User::factory()->create()))->toBe([]);
});

it('binds the database repository on the database driver', function () {
    config()->set('session.driver', 'database');

    expect(app(SessionRepository::class))->toBeInstanceOf(DatabaseSessionRepository::class);
});

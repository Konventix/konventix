<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Konventix\Identity\Models\User;
use Konventix\Identity\Sessions\IndexedRedisSessionHandler;
use Konventix\Identity\Sessions\RedisSessionIndexRepository;
use Konventix\Identity\Sessions\SessionRepository;

/**
 * `Sessions\IndexedRedisSessionHandler` and `Sessions\RedisSessionIndex`
 * against a real Redis, end to end through the session manager (D-IDP-17).
 *
 * Everything else in the suite runs `SESSION_DRIVER=array` (phpunit.xml),
 * so nothing else guards phpredis' actual return shapes — `hgetall` on a
 * missing key, `smembers` on an empty set, a TTL that really is set. This
 * test skips when Redis is unreachable rather than failing the whole suite
 * on an infrastructure gap, but it IS reachable in the dev container, so it
 * must actually run there, not skip.
 *
 * Two traps it stays clear of (`RedisWaitingRoomStoreTest`,
 * `MetricsRedisStorageTest`). It overrides `identity.sessions.index_prefix`
 * with a per-run, test-only prefix, so nothing here can touch the keys a
 * running instance sharing this Redis reads. And it deletes only the exact
 * keys it named, unprefixed the way every write in `RedisSessionIndex` names
 * them — never `keys()` + `del()` (which double-prefixes and deletes
 * nothing) and never a flush.
 *
 * Helpers prefixed `identityRedisSession*` — Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
function identityRedisSessionConnection(): mixed
{
    return Redis::connection(config('identity.sessions.connection') ?? config('session.connection'));
}

function identityRedisSessionReachable(): bool
{
    try {
        identityRedisSessionConnection()->ping();

        return true;
    } catch (Throwable) {
        return false;
    }
}

it('indexes, lists and revokes a session on a real redis', function () {
    if (! identityRedisSessionReachable()) {
        $this->markTestSkipped('session-index Redis unreachable');
    }

    $prefix = 'identity-sessions-test-'.Str::ulid().':';

    config([
        'session.driver' => 'redis',
        'identity.sessions.index_prefix' => $prefix,
    ]);

    $user = User::factory()->create();
    $sessionId = 'test-session-'.Str::ulid();

    $metaKey = $prefix.'meta:'.$sessionId;
    $userKey = $prefix.'user:'.$user->getKey();

    /** @var IndexedRedisSessionHandler $handler */
    $handler = session()->getHandler();

    try {
        expect($handler)->toBeInstanceOf(IndexedRedisSessionHandler::class);

        app()->instance('request', Request::create('/settings/security', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_USER_AGENT' => 'Real Redis Browser',
        ]));
        Auth::login($user);

        $handler->write($sessionId, 'the-payload');

        // Read straight out of Redis, not through the index: the point is
        // that phpredis really stored a hash and a set member.
        $connection = identityRedisSessionConnection();

        expect((array) $connection->hgetall($metaKey))->toMatchArray([
            'user_id' => (string) $user->getKey(),
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Real Redis Browser',
        ])
            ->and((array) $connection->smembers($userKey))->toBe([$sessionId])
            ->and($connection->ttl($metaKey))->toBeGreaterThan(0)
            ->and($connection->ttl($userKey))->toBeGreaterThan(0)
            ->and($handler->read($sessionId))->toBe('the-payload');

        $repository = app(SessionRepository::class);

        expect($repository)->toBeInstanceOf(RedisSessionIndexRepository::class)
            ->and($repository->available())->toBeTrue();

        $sessions = $repository->activeFor($user);

        expect($sessions)->toHaveCount(1)
            ->and($sessions[0]->id)->toBe($sessionId)
            ->and($sessions[0]->ipAddress)->toBe('203.0.113.7')
            ->and($sessions[0]->userAgent)->toBe('Real Redis Browser');

        // Revocation has to reach the payload, not only the index: a session
        // dropped from the list has to stop working.
        $repository->revoke($user, $sessionId);

        expect($handler->read($sessionId))->toBe('')
            ->and((int) $connection->exists($metaKey))->toBe(0)
            ->and((array) $connection->smembers($userKey))->toBe([])
            ->and($repository->activeFor($user))->toBe([]);
    } finally {
        identityRedisSessionConnection()->del($metaKey, $userKey);
        $handler->destroy($sessionId);
    }
});

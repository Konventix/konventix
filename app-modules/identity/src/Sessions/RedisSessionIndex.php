<?php

declare(strict_types=1);

namespace Konventix\Identity\Sessions;

use Illuminate\Support\Facades\Redis;

/**
 * The Redis-backed `SessionIndex`, one hash per session and one set per
 * user (D-IDP-17).
 *
 * The connection defaults to `session.connection` rather than the cache
 * store's own, because `SessionManager::createRedisDriver()` puts the
 * session payloads there too (`$store->setConnection(config('session.connection'))`,
 * so an unset `SESSION_CONNECTION` means the *default* connection, not the
 * `cache` one). Index and payload therefore live in the same database and a
 * flush of that database drops both halves at once — a half-index listing
 * sessions whose payload is gone is the one state this must not produce.
 * `config/identity.php` can override the connection and the key prefix.
 *
 * Keys are named unprefixed here, exactly as the client's own
 * `options.prefix` expects: phpredis prepends it to every write and read,
 * while `keys()` hands names back with it already applied, so nothing in
 * this class ever round-trips a `keys()` result (the trap
 * `RedisWaitingRoomStoreTest` documents). There is no `keys()` and no flush
 * in here at all: every command names the exact key it touches.
 *
 * Relies on `maxmemory-policy=noeviction` — see `SessionIndex`.
 */
final class RedisSessionIndex implements SessionIndex
{
    public function record(string $sessionId, string $userId, array $meta, int $ttlSeconds): void
    {
        $connection = $this->connection();
        $metaKey = $this->metaKey($sessionId);
        $userKey = $this->userKey($userId);

        // Redis stores strings; an unknown IP or user agent is written as an
        // empty field and read back as null (see `meta()`).
        $connection->hmset($metaKey, [
            'user_id' => $userId,
            'ip_address' => (string) ($meta['ip_address'] ?? ''),
            'user_agent' => (string) ($meta['user_agent'] ?? ''),
            'last_activity' => (string) $meta['last_activity'],
        ]);
        $connection->expire($metaKey, $ttlSeconds);

        $connection->sadd($userKey, $sessionId);
        $connection->expire($userKey, $ttlSeconds);
    }

    public function meta(string $sessionId): ?array
    {
        /** @var array<string, string> $hash */
        $hash = (array) $this->connection()->hgetall($this->metaKey($sessionId));

        if ($hash === [] || ! isset($hash['user_id'])) {
            return null;
        }

        return [
            'user_id' => (string) $hash['user_id'],
            'ip_address' => ($hash['ip_address'] ?? '') === '' ? null : (string) $hash['ip_address'],
            'user_agent' => ($hash['user_agent'] ?? '') === '' ? null : (string) $hash['user_agent'],
            'last_activity' => (int) ($hash['last_activity'] ?? 0),
        ];
    }

    public function sessionIdsFor(string $userId): array
    {
        /** @var list<string> $ids */
        $ids = array_values(array_map(strval(...), (array) $this->connection()->smembers($this->userKey($userId))));

        return $ids;
    }

    public function forget(string $sessionId): void
    {
        $meta = $this->meta($sessionId);

        if ($meta !== null) {
            $this->connection()->srem($this->userKey($meta['user_id']), $sessionId);
        }

        $this->connection()->del($this->metaKey($sessionId));
    }

    public function detach(string $userId, string $sessionId): void
    {
        $this->connection()->srem($this->userKey($userId), $sessionId);
    }

    /**
     * Resolved per call, never held: under Octane the handler that owns this
     * index lives for the whole worker, and a connection captured in a
     * constructor would outlive every request that reconfigured it.
     */
    private function connection(): mixed
    {
        return Redis::connection(
            config('identity.sessions.connection') ?? config('session.connection')
        );
    }

    private function metaKey(string $sessionId): string
    {
        return $this->prefix().'meta:'.$sessionId;
    }

    private function userKey(string $userId): string
    {
        return $this->prefix().'user:'.$userId;
    }

    private function prefix(): string
    {
        return (string) config('identity.sessions.index_prefix', 'sessions:');
    }
}

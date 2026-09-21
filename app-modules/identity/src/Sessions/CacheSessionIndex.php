<?php

declare(strict_types=1);

namespace Konventix\Identity\Sessions;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;

/**
 * The cache-backed `SessionIndex`: the same two structures as
 * `RedisSessionIndex`, expressed as two cache entries instead of a hash and
 * a set.
 *
 * It exists so the repository contract test and the Octane-staleness test
 * can drive the real handler on the array store (`CACHE_STORE=array`,
 * phpunit.xml) without a Redis. It is not a second production path: a set
 * rewritten whole on every write has no atomicity a concurrent login would
 * respect, which is precisely what `SADD` gives the Redis implementation.
 */
final class CacheSessionIndex implements SessionIndex
{
    public function __construct(private readonly CacheFactory $cache) {}

    public function record(string $sessionId, string $userId, array $meta, int $ttlSeconds): void
    {
        $this->store()->put($this->metaKey($sessionId), [
            'user_id' => $userId,
            'ip_address' => $meta['ip_address'] ?? null,
            'user_agent' => $meta['user_agent'] ?? null,
            'last_activity' => (int) ($meta['last_activity'] ?? 0),
        ], $ttlSeconds);

        $ids = $this->idsFor($userId);
        $ids[$sessionId] = true;

        $this->store()->put($this->userKey($userId), $ids, $ttlSeconds);
    }

    public function meta(string $sessionId): ?array
    {
        $meta = $this->store()->get($this->metaKey($sessionId));

        if (! is_array($meta) || ! isset($meta['user_id'])) {
            return null;
        }

        return [
            'user_id' => (string) $meta['user_id'],
            'ip_address' => $meta['ip_address'] === null ? null : (string) $meta['ip_address'],
            'user_agent' => $meta['user_agent'] === null ? null : (string) $meta['user_agent'],
            'last_activity' => (int) $meta['last_activity'],
        ];
    }

    public function sessionIdsFor(string $userId): array
    {
        return array_map(strval(...), array_keys($this->idsFor($userId)));
    }

    public function forget(string $sessionId): void
    {
        $meta = $this->meta($sessionId);

        if ($meta !== null) {
            $this->detach($meta['user_id'], $sessionId);
        }

        $this->store()->forget($this->metaKey($sessionId));
    }

    public function detach(string $userId, string $sessionId): void
    {
        $ids = $this->idsFor($userId);
        unset($ids[$sessionId]);

        if ($ids === []) {
            $this->store()->forget($this->userKey($userId));

            return;
        }

        // The remaining ids keep the lifetime a write would give them; the
        // array store ignores the TTL anyway and a real store re-expires on
        // the next write.
        $this->store()->put($this->userKey($userId), $ids, (int) config('session.lifetime') * 60);
    }

    /**
     * @return array<string, true>
     */
    private function idsFor(string $userId): array
    {
        $ids = $this->store()->get($this->userKey($userId));

        return is_array($ids) ? $ids : [];
    }

    /**
     * Resolved per call — see `RedisSessionIndex::connection()`.
     */
    private function store(): Repository
    {
        return $this->cache->store();
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

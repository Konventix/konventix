<?php

declare(strict_types=1);

namespace Konventix\Identity\Sessions;

/**
 * The per-user index `IndexedRedisSessionHandler` keeps beside the session
 * payloads, so the Security page can list a person's sessions on a driver
 * that stores nothing but an opaque blob per id (D-IDP-17).
 *
 * Two structures per user: one hash per session id holding the meta the
 * `sessions` table would have carried (`ip_address`, `user_agent`,
 * `user_id`, `last_activity`), and one set per user holding that user's
 * session ids. Both are re-expired on every write, so the index cannot
 * outlive the payload it describes.
 *
 * An interface rather than a class because the repository contract test
 * runs on the array cache store, where no Redis is reachable — see
 * `CacheSessionIndex`. The redis implementation relies on
 * `maxmemory-policy=noeviction`: an evicted hash is a session that silently
 * drops off the list while it still works, which is exactly the "empty
 * promise" the page was fixed not to make.
 */
interface SessionIndex
{
    /**
     * Record (or refresh) one session, and re-expire both structures.
     *
     * @param  array{user_id: string, ip_address: ?string, user_agent: ?string, last_activity: int}  $meta
     */
    public function record(string $sessionId, string $userId, array $meta, int $ttlSeconds): void;

    /**
     * The meta of one session, or null when the hash is gone (expired,
     * destroyed, or never written because nobody was signed in).
     *
     * @return array{user_id: string, ip_address: ?string, user_agent: ?string, last_activity: int}|null
     */
    public function meta(string $sessionId): ?array;

    /**
     * Every session id currently indexed for a user, in no particular order.
     *
     * @return list<string>
     */
    public function sessionIdsFor(string $userId): array;

    /**
     * Drop one session from the index: read its owner from the hash, remove
     * the id from that owner's set, delete the hash. A no-op when the hash
     * is already gone.
     */
    public function forget(string $sessionId): void;

    /**
     * Remove an id from a user's set without touching the hash — the prune
     * path for an id whose hash has expired underneath it.
     */
    public function detach(string $userId, string $sessionId): void;
}

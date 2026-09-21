<?php

declare(strict_types=1);

namespace Konventix\Identity\Sessions;

use Konventix\Identity\Models\User;

/**
 * The session list on the redis driver, read from the per-user index
 * `IndexedRedisSessionHandler` writes (D-IDP-17).
 *
 * Expiry needs no garbage collection and no job: every write re-expires
 * both the meta hash and the user's set with `session.lifetime`, so a hash
 * that is gone is a session redis has already dropped. `activeFor()` closes
 * the two gaps that leaves — an id still in the set whose hash expired
 * underneath it is pruned on the spot, and a hash whose `last_activity` is
 * older than the lifetime is not listed, the same honesty
 * `ActiveSessionsQuery` applies to the database rows (listing it would
 * offer a "Revoke" button for a session that is already gone).
 */
final class RedisSessionIndexRepository implements SessionRepository
{
    public function __construct(private readonly SessionIndex $index) {}

    /**
     * The driver being named `redis` is not enough: the stock
     * `SessionManager::createRedisDriver()` builds a plain
     * `CacheBasedSessionHandler` that writes no index at all, and a list
     * read from an index nothing fills would be empty forever. The page may
     * only promise a list when the handler in front of it is ours.
     */
    public function available(): bool
    {
        return session()->getHandler() instanceof IndexedRedisSessionHandler;
    }

    public function activeFor(User $user): array
    {
        $userId = (string) $user->getKey();
        $activeSince = now()->subMinutes((int) config('session.lifetime'))->getTimestamp();

        $summaries = [];

        foreach ($this->index->sessionIdsFor($userId) as $sessionId) {
            $meta = $this->index->meta($sessionId);

            if ($meta === null) {
                $this->index->detach($userId, $sessionId);

                continue;
            }

            if ($meta['last_activity'] < $activeSince) {
                continue;
            }

            $summaries[] = new SessionSummary(
                id: $sessionId,
                ipAddress: $meta['ip_address'],
                userAgent: $meta['user_agent'],
                lastActivity: $meta['last_activity'],
            );
        }

        usort($summaries, fn (SessionSummary $a, SessionSummary $b): int => $b->lastActivity <=> $a->lastActivity);

        return $summaries;
    }

    public function revoke(User $user, string $sessionId): void
    {
        $meta = $this->index->meta($sessionId);

        if ($meta === null) {
            // Nothing to revoke; the id may still be dangling in this user's
            // own set, so prune it and stop. A stranger's set is untouched.
            $this->index->detach((string) $user->getKey(), $sessionId);

            return;
        }

        if ($meta['user_id'] !== (string) $user->getKey()) {
            return;
        }

        $this->destroy($sessionId);
    }

    public function revokeOthers(User $user, string $exceptId): void
    {
        foreach ($this->index->sessionIdsFor((string) $user->getKey()) as $sessionId) {
            if ($sessionId === $exceptId) {
                continue;
            }

            $this->revoke($user, $sessionId);
        }
    }

    /**
     * Revocation has to reach the payload, not just the index: an id dropped
     * from the index alone would vanish from the list and keep working. The
     * handler owns both halves, so it is asked to destroy the session —
     * `IndexedRedisSessionHandler::destroy()` forgets the index first and
     * then deletes the payload. The index call here covers the case where
     * some other handler is in front of us (the contract test's array
     * store), where it is the only half there is.
     */
    private function destroy(string $sessionId): void
    {
        $this->index->forget($sessionId);

        session()->getHandler()->destroy($sessionId);
    }
}

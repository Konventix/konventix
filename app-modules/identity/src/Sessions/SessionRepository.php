<?php

declare(strict_types=1);

namespace Konventix\Identity\Sessions;

use Konventix\Identity\Models\User;

/**
 * What the Security page needs from whatever the session driver happens to
 * be (D-IDP-17): can this installation list sessions at all, which ones are
 * active for this account, and revoke one or all-but-one of them.
 *
 * Three implementations, chosen by `config('session.driver')` in
 * `IdentityServiceProvider::registerBindings()` — bound with `bind()`, never
 * `singleton()`, because the choice reads config and a test switches it
 * between two requests of one process. `DatabaseSessionRepository` wraps the
 * `sessions` table, `RedisSessionIndexRepository` the per-user index
 * `IndexedRedisSessionHandler` maintains, and `NullSessionRepository`
 * answers honestly on array/file/cookie, where nothing that could be listed
 * is stored.
 *
 * Every method takes the acting `User` and filters by it: these are
 * self-scoped reads and deletes, so there is no capability to check beyond
 * the `auth` middleware already on the routes (SPEC.md — "acting on one's
 * own account is a policy question, not a capability").
 */
interface SessionRepository
{
    /**
     * Whether this installation stores sessions a list can be read from and
     * a revocation can act on. False means the page says so instead of
     * presenting an empty list as "no other sessions".
     */
    public function available(): bool;

    /**
     * The user's sessions the driver still honours, newest activity first.
     *
     * @return list<SessionSummary>
     */
    public function activeFor(User $user): array;

    /**
     * Revoke one session, but only if it belongs to this user.
     */
    public function revoke(User $user, string $sessionId): void;

    /**
     * Revoke every session of this user except the one making the request.
     */
    public function revokeOthers(User $user, string $exceptId): void;
}

<?php

declare(strict_types=1);

namespace Konventix\Identity\Sessions;

use Konventix\Identity\Models\Session;
use Konventix\Identity\Models\User;
use Konventix\Identity\Queries\ActiveSessionsQuery;

/**
 * The `sessions` table behind the `SessionRepository` contract — the driver
 * the page was built on, unchanged in behaviour: `ActiveSessionsQuery` for
 * the read (including its "only what the handler still honours" filter) and
 * the two deletes that used to sit inline in `SessionController`.
 */
final class DatabaseSessionRepository implements SessionRepository
{
    public function __construct(private readonly ActiveSessionsQuery $sessions) {}

    public function available(): bool
    {
        return true;
    }

    public function activeFor(User $user): array
    {
        return array_values($this->sessions->for($user)
            ->map(fn (Session $session): SessionSummary => new SessionSummary(
                id: $session->id,
                ipAddress: $session->ip_address,
                userAgent: $session->user_agent,
                lastActivity: $session->last_activity,
            ))
            ->values()
            ->all());
    }

    public function revoke(User $user, string $sessionId): void
    {
        Session::query()
            ->whereKey($sessionId)
            ->where('user_id', $user->getKey())
            ->delete();
    }

    public function revokeOthers(User $user, string $exceptId): void
    {
        Session::query()
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $exceptId)
            ->delete();
    }
}

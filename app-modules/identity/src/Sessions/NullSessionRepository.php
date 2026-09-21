<?php

declare(strict_types=1);

namespace Konventix\Identity\Sessions;

use Konventix\Identity\Models\User;

/**
 * The answer on `array`, `file` and `cookie`: there is no store a list
 * could be read from or a revocation could act on, so the page is told so
 * (`sessionsAvailable` false) and says it, rather than showing an empty
 * list that reads as "no other sessions".
 *
 * The revocations are no-ops rather than exceptions: the routes exist on
 * every installation, and a request that reaches them on such a driver has
 * nothing to delete.
 */
final class NullSessionRepository implements SessionRepository
{
    public function available(): bool
    {
        return false;
    }

    public function activeFor(User $user): array
    {
        return [];
    }

    public function revoke(User $user, string $sessionId): void {}

    public function revokeOthers(User $user, string $exceptId): void {}
}

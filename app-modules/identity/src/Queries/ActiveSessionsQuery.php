<?php

declare(strict_types=1);

namespace Konventix\Identity\Queries;

use Illuminate\Support\Collection;
use Konventix\Identity\Models\Session;
use Konventix\Identity\Models\User;

/**
 * Reads, never writes (ADR-0015's "if it changes state it is an Action; if it
 * only reads it is a Query" — the full authorizing-Query dispatcher ADR-0015
 * describes is not built anywhere in the codebase yet, so this is a plain
 * class in that spirit rather than a wired implementation of its contracts).
 *
 * Self-scoped by construction — `SecurityController` never passes another
 * user's ULID in — so there is nothing here to authorize beyond the `auth`
 * middleware already on the route (SPEC.md capabilities: "Acting on one's own
 * account is a policy question, not a capability").
 *
 * Deliberately does not filter by brand: the `sessions` table has no
 * `brand_id` column (see `Models\Session`), so this returns every session row
 * for the account across every brand it has ever logged into. The Security
 * page states "sessions on this brand" per the task brief, but that is a
 * simplification, not a guarantee this query enforces — see TODO.md.
 *
 * "Active" is measured the way the session handler measures it: a row whose
 * `last_activity` is older than `session.lifetime` is one the handler would
 * refuse to read and the garbage collector will delete, so it is not listed
 * — listing it offered a "Revoke" button for a session that was already gone.
 */
final class ActiveSessionsQuery
{
    /**
     * @return Collection<int, Session>
     */
    public function for(User $user): Collection
    {
        return Session::query()
            ->where('user_id', $user->getKey())
            ->where('last_activity', '>=', self::activeSince())
            ->orderByDesc('last_activity')
            ->get();
    }

    /**
     * The oldest `last_activity` the session handler still honours, as the
     * Unix timestamp the column stores.
     */
    private static function activeSince(): int
    {
        return now()->subMinutes((int) config('session.lifetime'))->getTimestamp();
    }
}

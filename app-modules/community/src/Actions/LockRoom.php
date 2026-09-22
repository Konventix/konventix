<?php

declare(strict_types=1);

namespace Konventix\Community\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Konventix\Community\Models\Room;

/**
 * Shuts the wall without shutting the room (spec §6, D-COM-08; ADR-0015).
 *
 * The third moderation verb, and the one for 03:00 when the wall has gone
 * bad and nobody wants to moderate line by line: existing messages stay
 * **readable**, nobody may post, and it is **reversible** — that is what
 * separates a lock from `closed_at`, which `EventConcluded` sets once and
 * which never reopens (spec §4.1).
 *
 * **Nothing but this Action keeps the lock triple consistent.** No database
 * CHECK ties `locked_at`, `locked_by_user_id` and `locked_reason`, so all
 * three are written in one `forceFill` inside one transaction — and
 * `UnlockRoom` clears all three the same way.
 *
 * Locking an already-locked room leaves the first lock alone: who locked
 * the wall and why is the answer the console shows, and a second click
 * must not rewrite it.
 *
 * **A closed room is left alone too**, which is the half `UnlockRoom`
 * already had: it refuses to clear a lock on a closed room, so a lock
 * applied to one could never be lifted. A closed wall refuses every post
 * on its own (`PostMessage` checks `closed_at` first), so the lock would
 * buy nothing and cost the console a room shown as "gesperrt" with no way
 * to clear it.
 *
 * `$reason` is nullable because the column is (spec §4.1) — unlike a mute's
 * reason, this one is not shown to a named person, and an operator shutting
 * a wall at 03:00 should not have to write an essay first.
 *
 * The capability gate is `Permission::ModerateCommunity` and is the
 * surface's job (D-COM-04).
 */
final class LockRoom
{
    /**
     * The `varchar(255)` of `community_rooms.locked_reason` (spec §4.1),
     * checked here rather than left to the column: an overflow is a 500,
     * not an answer.
     */
    public const int MAX_REASON_LENGTH = 255;

    public function handle(Room $room, string $byUserId, ?string $reason = null): Room
    {
        if ($room->locked_at !== null || $room->closed_at !== null) {
            return $room;
        }

        $trimmed = trim($reason ?? '');

        if (mb_strlen($trimmed) > self::MAX_REASON_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'A lock reason is at most %d characters; `locked_reason` is a varchar(%d).',
                self::MAX_REASON_LENGTH,
                self::MAX_REASON_LENGTH,
            ));
        }

        // An empty sentence is worse than none: the console would render a
        // blank explanation for a lock that has one.
        $reason = $trimmed === '' ? null : $trimmed;

        return DB::transaction(function () use ($room, $byUserId, $reason): Room {
            $room->forceFill([
                'locked_at' => CarbonImmutable::now()->utc(),
                'locked_by_user_id' => $byUserId,
                'locked_reason' => $reason,
            ])->save();

            return $room;
        });
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Community\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
 * `$reason` is nullable because the column is (spec §4.1) — unlike a mute's
 * reason, this one is not shown to a named person, and an operator shutting
 * a wall at 03:00 should not have to write an essay first.
 *
 * The capability gate is `Permission::ModerateCommunity` and is the
 * surface's job (D-COM-04).
 */
final class LockRoom
{
    public function handle(Room $room, string $byUserId, ?string $reason = null): Room
    {
        if ($room->locked_at !== null) {
            return $room;
        }

        $reason = $reason === null ? null : (trim($reason) === '' ? null : trim($reason));

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

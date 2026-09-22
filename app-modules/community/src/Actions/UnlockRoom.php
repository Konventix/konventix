<?php

declare(strict_types=1);

namespace Konventix\Community\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Community\Models\Room;

/**
 * Reopens a locked wall (spec §6, D-COM-08; ADR-0015).
 *
 * The reverse of `LockRoom`, and it clears the whole lock triple —
 * `locked_at`, `locked_by_user_id`, `locked_reason` — in one write. No
 * database CHECK ties them, so leaving a stale `locked_reason` behind would
 * hand the console a sentence about a lock that is no longer in force.
 *
 * **A closed room is not unlockable.** `closed_at` is `EventConcluded`'s
 * and starts the retention clock (spec §4.1, §7); a closed room is readable
 * until the sweep takes it and never writable again. Clearing a lock on one
 * is harmless but meaningless, so this leaves it exactly as it found it
 * rather than suggesting the wall is open.
 */
final class UnlockRoom
{
    public function handle(Room $room): Room
    {
        if ($room->locked_at === null || $room->closed_at !== null) {
            return $room;
        }

        return DB::transaction(function () use ($room): Room {
            $room->forceFill([
                'locked_at' => null,
                'locked_by_user_id' => null,
                'locked_reason' => null,
            ])->save();

            return $room;
        });
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Community\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Konventix\Community\Models\Room;
use Konventix\Community\Models\RoomMute;

/**
 * Lifts every mute a person is still serving in one room (spec §6,
 * D-COM-08; ADR-0015).
 *
 * **It expires the rows rather than deleting them.** `community_room_mutes`
 * is a log — "this person was silenced for this reason at this time" is a
 * moderation fact the desk may need to read back, and deleting it would
 * make an event's history depend on whether somebody was let off early.
 * Setting `expires_at` to now is exactly what `PostMessage`'s gate asks
 * about, so the effect is immediate.
 *
 * **Every** mute in force, not just the newest: a person can carry an
 * indefinite mute under a timed one, and lifting only the row the console
 * happened to show would leave them silenced with nothing on screen to
 * explain it.
 *
 * Returns how many rows were lifted, so a console can say "nothing to
 * lift" rather than claim an act that did not happen.
 */
final class UnmuteParticipant
{
    public function handle(Room $room, string $userId): int
    {
        $now = CarbonImmutable::now()->utc();

        return DB::transaction(fn (): int => RoomMute::withoutBrandScope()
            // Explicit brand rather than the ambient scope: the console
            // runs on the operator admin host, where `BrandScope` hardens
            // every query to zero rows (ADR-0029) and an unlifted mute
            // would look exactly like a successful unmute.
            ->where('brand_id', $room->brand_id)
            ->where('room_id', $room->id)
            ->where('user_id', $userId)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->update(['expires_at' => $now]));
    }
}

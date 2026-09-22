<?php

declare(strict_types=1);

namespace Konventix\Community\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Konventix\Community\Data\MuteData;
use Konventix\Community\Models\Room;
use Konventix\Community\Models\RoomMute;

/**
 * Silences one person in one room (spec §4.3, §6, D-COM-08; ADR-0015).
 *
 * Twitch-shaped, and deliberately so: a **timed** mute with a **required
 * reason that is shown to the muted person**. `$minutes` null means "for
 * the rest of the event" and leaves `expires_at` null — the indefinite
 * case of spec §4.3, not a missing value.
 *
 * Mutes are per **room**. In 0.9.0 that is the same as per event, because
 * there is one room; when match rooms arrive (sub-project B), a mute that
 * should span them becomes a visible decision rather than an accident of
 * the schema (ADR-0067, "Consequences").
 *
 * The table is a **log**, not current state: this always writes a new row
 * rather than updating an old one, so an event's moderation history stays
 * readable. `PostMessage` asks which of a person's rows is still in force.
 *
 * No event is dispatched. Spec §9 publishes three, and a mute is not among
 * them: who has been silenced is a moderation fact inside Community, and
 * nothing outside it has business reacting to one.
 *
 * The capability gate is `Permission::ModerateCommunity` and is the
 * surface's job (D-COM-04) — an organizer who was never scanned in must be
 * able to do this, so nothing here reads `community_event_presence`.
 */
final class MuteParticipant
{
    public function handle(Room $room, string $userId, string $byUserId, string $reason, ?int $minutes): RoomMute
    {
        $reason = trim($reason);

        if ($reason === '') {
            // The column is NOT NULL (spec §4.3) and would refuse this with
            // a constraint violation; the operator deserves a sentence.
            throw new InvalidArgumentException('A mute needs a reason; the muted person is shown it.');
        }

        if (mb_strlen($reason) > MuteData::MAX_REASON_LENGTH) {
            // The column is a `varchar(255)` (spec §4.3) and would refuse
            // this with `SQLSTATE[22001]` — a 500 that also aborts whatever
            // transaction the console had open around it
            // (`.ai/rules/tests.md`). `MuteData` declares the length for the
            // form; this is where a caller that skipped the form meets it.
            throw new InvalidArgumentException(sprintf(
                'A mute reason is at most %d characters; the muted person reads it, and `reason` is a varchar(%d).',
                MuteData::MAX_REASON_LENGTH,
                MuteData::MAX_REASON_LENGTH,
            ));
        }

        if ($minutes !== null && ($minutes < 1 || $minutes > MuteData::MAX_MINUTES)) {
            throw new InvalidArgumentException(sprintf(
                'A timed mute runs between 1 and %d minutes; pass null for the rest of the event.',
                MuteData::MAX_MINUTES,
            ));
        }

        $now = CarbonImmutable::now()->utc();

        return DB::transaction(function () use ($room, $userId, $byUserId, $reason, $minutes, $now): RoomMute {
            $mute = new RoomMute([
                'room_id' => $room->id,
                'user_id' => $userId,
                'muted_by_user_id' => $byUserId,
                'reason' => $reason,
                'expires_at' => $minutes === null ? null : $now->addMinutes($minutes),
            ]);

            // The room's brand, never ambient context (ADR-0010): the
            // console runs on the operator admin host, where no brand is
            // resolved at all.
            $mute->forceFill(['brand_id' => $room->brand_id]);
            $mute->save();

            return $mute;
        });
    }
}

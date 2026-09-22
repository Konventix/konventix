<?php

declare(strict_types=1);

namespace Konventix\Community\Rooms;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Konventix\Community\Broadcasting\RoomChannel;
use Konventix\Community\Enums\RoomSubject;
use Konventix\Community\Models\Room;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Hub\ActiveEventContext;

/**
 * Which room a participant surface is about, and whether this viewer may be
 * in it (design §8, D-COM-03).
 *
 * **The client never names a room.** Every participant surface — the hub
 * card, `/live/shoutbox`, its fetches and its compose box — is about the
 * running event this member is admitted to, which is Platform's
 * `ActiveEventContext`'s answer (ADR-0004), and the room is derived from
 * that. A request that could say "room X" would be a client choosing the
 * room it wants, which is the one thing the access gate exists to refuse.
 *
 * **Access is `RoomChannel::join()`, not a second copy of it.** The four
 * checks the channel subscription makes — the room exists, `community` is
 * on for its brand, the resolved brand is its brand, moderation capability
 * or admission to its event — are the same checks every HTTP read owes, so
 * `authorised()` asks the channel rather than re-deriving them.
 *
 * **Created lazily, keyed on the brand** (spec §4.1). The event's room is
 * created on the first read, with no listener and no backfill. The lookup
 * and the creation both carry the brand as well as the subject, because
 * `community_rooms`' unique index is on `(subject_type, subject_id)` alone
 * and is **not** brand-scoped: a row some other brand already holds for
 * this subject id is not this brand's room, and this class refuses rather
 * than handing it over. Event ids are ULIDs and a room is only ever created
 * here, from an admission, so that row should never exist — but a lookup
 * that ignored the brand would hand one brand's wall to another the day it
 * did.
 *
 * Stateless: the scoped bindings are resolved per call, never kept on
 * `$this` (`.ai/rules/providers.md`).
 */
final class EventRooms
{
    /**
     * The room of the running event this viewer is admitted to, if they may
     * read it; null for everyone else, which the surface answers with 404.
     */
    public function forViewer(Authenticatable $user): ?Room
    {
        $event = app(ActiveEventContext::class)->current();

        return $event === null ? null : $this->authorised($event->eventId, $user);
    }

    /**
     * An event's room, created if nobody has opened it yet, handed over only
     * when `RoomChannel::join()` lets this viewer in.
     */
    public function authorised(string $eventId, Authenticatable $user): ?Room
    {
        $room = $this->ofEvent($eventId);

        if ($room === null || ! app(RoomChannel::class)->join($user, $room->id)) {
            return null;
        }

        return $room;
    }

    /**
     * An event's room on the resolved brand, created on first read. No
     * access check — `authorised()` is the one callers want, and
     * `ConnectionState::enterRoom()` makes its own.
     *
     * A concurrent first read by two participants meets the unique index;
     * the loser reads the winner's row. The insert runs in its own
     * transaction so that the violation rolls back a savepoint and not
     * whatever the caller had open.
     */
    public function ofEvent(string $eventId): ?Room
    {
        $brandId = app(BrandContext::class)->id();

        if ($brandId === null) {
            return null;
        }

        $room = $this->find($brandId, $eventId);

        if ($room !== null) {
            return $room;
        }

        try {
            return DB::transaction(function () use ($brandId, $eventId): Room {
                $room = new Room([
                    'subject_type' => RoomSubject::Event,
                    'subject_id' => $eventId,
                ]);

                // The resolved brand, stamped explicitly rather than left to
                // `BelongsToBrand`'s hook: the lookup above is keyed on it,
                // and the row it creates has to be the row that lookup finds.
                $room->forceFill(['brand_id' => $brandId]);
                $room->save();

                return $room;
            });
        } catch (UniqueConstraintViolationException) {
            // Either a concurrent first read won — its row is this brand's
            // and the lookup finds it — or another brand holds this subject
            // id, and the lookup finds nothing, which refuses.
            return $this->find($brandId, $eventId);
        }
    }

    private function find(string $brandId, string $eventId): ?Room
    {
        return Room::withoutBrandScope()
            ->forBrand($brandId)
            ->where('subject_type', RoomSubject::Event)
            ->where('subject_id', $eventId)
            ->first();
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Community\Rooms;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Konventix\Community\Data\PostMessageData;
use Konventix\Community\Data\ShoutboxFeedData;
use Konventix\Community\Data\ShoutboxLineData;
use Konventix\Community\Data\ShoutboxPresenceData;
use Konventix\Community\Data\ShoutboxTombstoneData;
use Konventix\Community\Data\ShoutboxWallData;
use Konventix\Community\Enums\ConnectionStatus;
use Konventix\Community\Enums\RoomSubject;
use Konventix\Community\Exceptions\ShoutboxWriteException;
use Konventix\Community\Models\CommunityProfile;
use Konventix\Community\Models\EventPresence;
use Konventix\Community\Models\Message;
use Konventix\Community\Models\Room;
use Konventix\Community\Models\RoomMute;
use Konventix\Community\Presence\ConnectionState;

/**
 * The participant's read side of one room (design §8): the wall, the pages
 * before and after a cursor, and the presence list.
 *
 * **Every method assumes the caller already holds an authorised room** —
 * `EventRooms::authorised()` or `ConnectionState::enterRoom()` — and none of
 * them checks again. `ConnectionState::forRoom()` in particular makes no
 * room check of its own; `presence()` is only ever reached from a surface
 * that did.
 *
 * **A removed line leaves here as a tombstone and nothing else.** The row
 * still holds its body, its author and the desk's reason (spec §4.2), and
 * none of the three reaches a participant: `present()` turns it into
 * `ShoutboxTombstoneData`, the same class the removal broadcast is built
 * from, so a client catching up over HTTP learns exactly what the socket
 * would have told it.
 *
 * **Paging is by id** (spec §4.2): ULIDs sort by the instant they were
 * minted, so `id > cursor` is "everything since that line" and runs on the
 * `(room_id, id)` index — no timestamp to trust and no new column.
 *
 * The room's brand scope is the ambient one: these reads run on the brand
 * host inside a request that resolved the room's own brand, which
 * `RoomChannel::join()` has already asserted.
 */
final class WallReader
{
    /** How many lines the room page opens with, and how many one scroll upward loads. */
    public const int PAGE_SIZE = 50;

    /** How many lines the hub card shows. */
    public const int CARD_LINES = 5;

    /** How many lines one reconciliation fetch returns before it says "there is more". */
    public const int CATCH_UP_LIMIT = 100;

    public function wall(Room $room, string $viewerId, int $lines): ShoutboxWallData
    {
        $syncedAt = $this->syncInstant();
        $rows = $this->messages($room)->orderByDesc('id')->limit($lines + 1)->get();
        $mute = $this->muteRefusal($room, $viewerId);

        return new ShoutboxWallData(
            roomId: $room->id,
            lines: $this->present($rows->take($lines)->reverse()),
            hasOlder: $rows->count() > $lines,
            notice: $this->roomNotice($room),
            mute: $mute?->getMessage(),
            retryAfterSeconds: $mute?->retryAfterSeconds,
            maxLength: PostMessageData::MAX_BODY_LENGTH,
            syncedAt: $syncedAt,
        );
    }

    /**
     * Everything since `$cursor`, oldest first — the reconciliation a client
     * runs when its socket comes back, so a dropped connection does not
     * leave the wall silently frozen. Lines newer than the cursor that were
     * removed in the meantime come back as tombstones among `lines`.
     *
     * `$since` covers what the id cursor cannot: a line the client already
     * held that was removed while it was away. It is the `syncedAt` of the
     * client's previous answer, and `removed` carries a tombstone for every
     * line of this room removed at or after it. "At or after", because
     * `deleted_at` is stored to the second and `syncedAt` is printed to the
     * second — a removal in the same second as the last answer is sent
     * again rather than missed, and applying a tombstone twice changes
     * nothing. A row filter over the room's own index; no new column.
     */
    public function after(Room $room, string $cursor, ?CarbonInterface $since = null): ShoutboxFeedData
    {
        $syncedAt = $this->syncInstant();

        $rows = $this->messages($room)
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit(self::CATCH_UP_LIMIT + 1)
            ->get();

        $removed = $since === null ? [] : $this->messages($room)
            ->where('id', '<=', $cursor)
            ->where('deleted_at', '>=', $since)
            ->orderBy('id')
            ->get()
            ->map(fn (Message $message): ShoutboxTombstoneData => $this->tombstoneOf($message))
            ->all();

        return new ShoutboxFeedData(
            lines: $this->present($rows->take(self::CATCH_UP_LIMIT)),
            hasMore: $rows->count() > self::CATCH_UP_LIMIT,
            removed: $removed,
            syncedAt: $syncedAt,
        );
    }

    /**
     * One page before `$cursor`, oldest first — scrolling upward.
     */
    public function before(Room $room, string $cursor): ShoutboxFeedData
    {
        $rows = $this->messages($room)
            ->where('id', '<', $cursor)
            ->orderByDesc('id')
            ->limit(self::PAGE_SIZE + 1)
            ->get();

        return new ShoutboxFeedData(
            lines: $this->present($rows->take(self::PAGE_SIZE)->reverse()),
            hasMore: $rows->count() > self::PAGE_SIZE,
            removed: [],
            syncedAt: $this->syncInstant(),
        );
    }

    /**
     * The line a post just created, as its author's compose box receives it.
     */
    public function line(Message $message): ShoutboxLineData
    {
        return $this->lineOf($message, $this->displayNames([$message->author_user_id]));
    }

    /**
     * Who is here (design §5): everyone active in this room on the
     * connection axis — they opened the wall, so they disclosed themselves
     * to it — each with the Checked-In badge beside the dot when the door
     * has scanned them in. Online first, then away, each by name.
     *
     * **Not an attendee roster** (ADR-0064). Somebody the door checked in
     * who never opened the wall is not listed: an attendee list is opt-in
     * per person and per event, and being scanned in is no consent to be
     * named to every admitted participant. That drops design §5's
     * "Checked-In + Offline" row. Provisional ruling of the 0.9.0 final
     * review (I1); whether absent people who opted in may appear, by
     * username, is the operator's open question (#324).
     *
     * No account ULID leaves here either (ADR-0064 §3): the page renders a
     * name, a dot and a badge, and needs nothing to address a row by.
     *
     * The badge is read from Community's own projection and only displayed
     * (ADR-0067 decision 2); nothing here decides anything on it.
     *
     * @return list<ShoutboxPresenceData>
     */
    public function presence(Room $room): array
    {
        // Reported, not raised: Redis being unreachable empties the list,
        // never the page.
        $connections = rescue(fn (): array => app(ConnectionState::class)->forRoom($room), []);
        $userIds = array_map(strval(...), array_keys($connections));

        if ($userIds === []) {
            return [];
        }

        $badged = $room->subject_type === RoomSubject::Event
            ? array_flip(array_values(array_filter(EventPresence::query()
                ->where('event_id', $room->subject_id)
                ->where('checked_in', true)
                ->whereIn('user_id', $userIds)
                ->pluck('user_id')
                ->all(), is_string(...))))
            : [];

        $names = $this->displayNames($userIds);

        $rows = array_map(static fn (string $userId): ShoutboxPresenceData => new ShoutboxPresenceData(
            displayName: $names[$userId] ?? null,
            status: $connections[$userId],
            checkedIn: isset($badged[$userId]),
        ), $userIds);

        $rank = [ConnectionStatus::Online->value => 0, ConnectionStatus::Away->value => 1, ConnectionStatus::Offline->value => 2];

        usort($rows, static fn (ShoutboxPresenceData $a, ShoutboxPresenceData $b): int => [$rank[$a->status->value], $a->displayName ?? '']
            <=> [$rank[$b->status->value], $b->displayName ?? '']);

        return $rows;
    }

    /**
     * @return Builder<Message>
     */
    private function messages(Room $room): Builder
    {
        return Message::query()->where('room_id', $room->id);
    }

    /**
     * Lines and tombstones in the order given, with one profile read for
     * every author on the page.
     *
     * @param  iterable<Message>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function present(iterable $messages): array
    {
        $messages = Collection::make($messages)->values();
        $names = $this->displayNames($messages->reject(fn (Message $message): bool => $message->isRemoved())
            ->pluck('author_user_id')
            ->all());

        return $messages
            ->map(fn (Message $message): array => ($message->isRemoved()
                ? $this->tombstoneOf($message)
                : $this->lineOf($message, $names))->toArray())
            ->all();
    }

    /**
     * The broadcast's tombstone shape and nothing else — never the reason,
     * the organizer, the body or the author (spec §4.2).
     */
    private function tombstoneOf(Message $message): ShoutboxTombstoneData
    {
        return new ShoutboxTombstoneData(
            id: $message->id,
            roomId: $message->room_id,
            removedAt: $message->deleted_at?->toIso8601String() ?? '',
        );
    }

    /**
     * The server's instant for one answer, to the second — what the client
     * hands back as `since` on its next catch-up.
     */
    private function syncInstant(): string
    {
        return CarbonImmutable::now()->utc()->toIso8601String();
    }

    /**
     * @param  array<string, string>  $names
     */
    private function lineOf(Message $message, array $names): ShoutboxLineData
    {
        return new ShoutboxLineData(
            id: $message->id,
            roomId: $message->room_id,
            authorUserId: $message->author_user_id,
            authorDisplayName: $names[$message->author_user_id] ?? null,
            body: $message->body,
            postedAt: $message->created_at?->toIso8601String() ?? '',
        );
    }

    /**
     * From Community's own profile read model (ADR-0004); operator-global,
     * so there is no brand scope to escape (ADR-0016).
     *
     * @param  array<int, mixed>  $userIds  as `pluck()` hands them over
     * @return array<string, string>
     */
    private function displayNames(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, is_string(...))));

        if ($userIds === []) {
            return [];
        }

        /** @var array<string, string> */
        return CommunityProfile::query()
            ->whereIn('id', $userIds)
            ->pluck('display_name', 'id')
            ->all();
    }

    /**
     * The sentence a refused post would carry, told before anybody types:
     * closed outranks locked, as it does in `PostMessage`.
     */
    private function roomNotice(Room $room): ?string
    {
        return match (true) {
            $room->closed_at !== null => ShoutboxWriteException::roomClosed()->getMessage(),
            $room->locked_at !== null => ShoutboxWriteException::roomLocked()->getMessage(),
            default => null,
        };
    }

    /**
     * The muted person is told the reason and the remaining time (D-COM-08)
     * — through the very refusal `PostMessage` would throw, so the sentence
     * and the countdown are the ones a post would get.
     */
    private function muteRefusal(Room $room, string $viewerId): ?ShoutboxWriteException
    {
        $now = CarbonImmutable::now()->utc();
        $mute = RoomMute::strongestInForce($room, $viewerId, $now);

        return $mute === null ? null : ShoutboxWriteException::muted($mute->reason, $mute->expires_at, $now);
    }
}

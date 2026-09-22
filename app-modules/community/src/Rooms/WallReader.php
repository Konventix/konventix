<?php

declare(strict_types=1);

namespace Konventix\Community\Rooms;

use Carbon\CarbonImmutable;
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
        );
    }

    /**
     * Everything since `$cursor`, oldest first — the reconciliation a client
     * runs when its socket comes back, so a dropped connection does not
     * leave the wall silently frozen. Lines removed in the meantime come
     * back as tombstones.
     */
    public function after(Room $room, string $cursor): ShoutboxFeedData
    {
        $rows = $this->messages($room)
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit(self::CATCH_UP_LIMIT + 1)
            ->get();

        return new ShoutboxFeedData(
            lines: $this->present($rows->take(self::CATCH_UP_LIMIT)),
            hasMore: $rows->count() > self::CATCH_UP_LIMIT,
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
     * Who is here (design §5): everyone active in the room on the
     * connection axis, and everyone the door has checked in, whether or not
     * they are online — "Checked-In + Offline" is a real row. Online first,
     * then away, then offline, each by name.
     *
     * The badge is read from Community's own projection and only displayed
     * (ADR-0067 decision 2); nothing here decides anything on it.
     *
     * @return list<ShoutboxPresenceData>
     */
    public function presence(Room $room): array
    {
        // Reported, not raised: Redis being unreachable costs the dots, not
        // the page — the badge below is durable and still renders.
        $connections = rescue(fn (): array => app(ConnectionState::class)->forRoom($room), []);

        $checkedIn = $room->subject_type === RoomSubject::Event
            ? array_values(array_filter(EventPresence::query()
                ->where('event_id', $room->subject_id)
                ->where('checked_in', true)
                ->pluck('user_id')
                ->all(), is_string(...)))
            : [];

        $userIds = array_values(array_unique([...array_map(strval(...), array_keys($connections)), ...$checkedIn]));
        $names = $this->displayNames($userIds);
        $badged = array_flip($checkedIn);

        $rows = array_map(static fn (string $userId): ShoutboxPresenceData => new ShoutboxPresenceData(
            userId: $userId,
            displayName: $names[$userId] ?? null,
            status: $connections[$userId] ?? ConnectionStatus::Offline,
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
                ? new ShoutboxTombstoneData(
                    id: $message->id,
                    roomId: $message->room_id,
                    removedAt: $message->deleted_at?->toIso8601String() ?? '',
                )
                : $this->lineOf($message, $names))->toArray())
            ->all();
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

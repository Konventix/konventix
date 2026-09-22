<?php

declare(strict_types=1);

namespace Konventix\Community\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Konventix\Community\Broadcasting\RoomChannel;
use Konventix\Community\Data\ShoutboxTombstoneData;

/**
 * An organizer removed a shoutbox line (spec §9, D-COM-08).
 *
 * The removed body is **not** here (spec §9, ADR-0004). `reason` is the
 * organizer's own note about the act — the thing the desk typed, not the
 * thing the participant did — and it is the one free-text field spec §9
 * puts on a payload.
 *
 * The message row itself survives as a tombstone (spec §4.2): a report
 * naming it still resolves, and the desk can still see what it acted on.
 * So a consumer receiving this must not read it as "the row is gone".
 *
 * `removedAt` is an ISO-8601 string; see `ShoutboxMessagePosted`.
 *
 * **The broadcast payload is narrower than this one, not wider.**
 * `ShouldBroadcastNow` tells every client in the room to turn the line into
 * a tombstone, and a tombstone "carries neither body nor author to other
 * participants" (spec §4.2) — so `broadcastWith()` sends the message id,
 * the room and the instant, and deliberately leaves out both `reason` and
 * `removedByUserId`. The organizer's note is the desk's, and the desk reads
 * it through the moderation console; the room is told that a line is gone,
 * not who took it down or what they wrote about it.
 *
 * See `ShoutboxMessagePosted` for why the domain payload and the wire
 * payload are two separate things.
 *
 * **A broadcast that fails must not fail the write.** `PostMessage`
 * dispatches this *after* the commit, so by the time Reverb is asked the
 * row is already durable — and a `BroadcastException` thrown out of
 * `event()` would therefore tell a participant their line was refused when
 * it was in fact accepted. They retype it and the wall carries it twice.
 * Reverb throwing is not hypothetical: it has been seen on the dev
 * instance. `ShouldRescue` is the framework's own answer —
 * `BroadcastManager::queue()` wraps the dispatch in `rescue()`, which
 * `report()`s the failure to the exception handler and swallows it — so the
 * broadcast stays `Now`, the after-the-commit ordering the reviewer asked
 * for is untouched, and no try/catch is bolted on by hand.
 *
 * (`NotificationReceived` needs none of this: the bell broadcasts from
 * inside `SendQueuedMessage`, where a throw fails a queued job that retries
 * and no participant's write is on the line. The difference is where the
 * dispatch happens, not the transport.)
 */
final readonly class ShoutboxMessageRemoved implements ShouldBroadcastNow, ShouldRescue
{
    public function __construct(
        public string $messageId,
        public string $roomId,
        public string $brandId,
        public string $removedByUserId,
        public string $reason,
        public string $removedAt,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(RoomChannel::nameFor($this->roomId));
    }

    public function broadcastAs(): string
    {
        return 'shoutbox.message.removed';
    }

    /**
     * `ShoutboxTombstoneData`'s shape, and only that: the same class the
     * room page and the reconciliation fetch replay a removal through, so a
     * client that missed this broadcast and catches up over HTTP is told
     * exactly what this payload tells it — and never `reason` or
     * `removedByUserId`.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return (new ShoutboxTombstoneData(
            id: $this->messageId,
            roomId: $this->roomId,
            removedAt: $this->removedAt,
        ))->toArray();
    }
}

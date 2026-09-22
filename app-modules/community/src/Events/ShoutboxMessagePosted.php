<?php

declare(strict_types=1);

namespace Konventix\Community\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Konventix\Community\Broadcasting\RoomChannel;
use Konventix\Community\Data\ShoutboxLineData;
use Konventix\Community\Models\CommunityProfile;
use Konventix\Community\Models\Message;

/**
 * A shoutbox line was accepted (spec §9).
 *
 * **No body text travels on this event, and that is a rule rather than an
 * omission** (spec §9, ADR-0004): a consumer that needs what was said reads
 * it through Community's own surface. An event payload is not a place to
 * copy user-generated content — it fans out to every listener in the
 * application, including ones written long after the person typed the
 * sentence.
 *
 * Nothing consumes this in 0.9.0. It exists so that auto-moderation through
 * an API (D-COM-02) can arrive later as a listener that writes a
 * `community_message_reports` row, without Community learning what a
 * classifier is and without the desk's workflow changing.
 *
 * `eventId` is the room's subject id. In 0.9.0 every room's subject is an
 * event (`RoomSubject::Event`) — per-match and per-competition rooms wait on
 * sub-project B, and the field needs revisiting when they arrive.
 *
 * `postedAt` is an ISO-8601 string, not a Carbon: a payload carries scalars
 * so that it survives serialisation onto a queue unchanged.
 *
 * **The broadcast is a second, separate payload, and the two must not be
 * confused.** `ShouldBroadcastNow` makes this class the browser's message
 * too (design §8, the notification bell's proven path), but the class's own
 * constructor properties — what a PHP listener in another module receives —
 * stay ULIDs and scalars with no body text, exactly as the paragraph above
 * says. What the page has to render travels only inside `broadcastWith()`'s
 * return value, which is built for one private channel whose subscribers
 * the room already authorised. So both halves of §9 hold: no consumer of
 * the domain event is handed user-generated text, and the chat does not
 * round-trip a fetch per line.
 *
 * `ShouldBroadcastNow` rather than `ShouldBroadcast` for the bell's reason:
 * this is dispatched from `PostMessage` after the commit, in the request
 * that accepted the line, and a queued broadcast would only add a hop to
 * say the same thing a fraction of a second later.
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
final readonly class ShoutboxMessagePosted implements ShouldBroadcastNow, ShouldRescue
{
    public function __construct(
        public string $messageId,
        public string $roomId,
        public string $brandId,
        public string $eventId,
        public string $authorUserId,
        public string $postedAt,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(RoomChannel::nameFor($this->roomId));
    }

    public function broadcastAs(): string
    {
        return 'shoutbox.message.posted';
    }

    /**
     * The wire payload — the browser's, not a module's. The body is read
     * here, at broadcast time, rather than carried on the event: it exists
     * only inside this return value and never as a property another
     * listener could reach.
     *
     * `body` is null when the row cannot be read back, which
     * `PostMessage`'s after-the-commit dispatch is designed to make
     * impossible; the client then has the id and can fetch the line
     * itself rather than render an empty one.
     *
     * The shape is `ShoutboxLineData`'s, the same class every HTTP read of
     * the wall uses, so the socket and the page cannot disagree about what
     * a line is.
     *
     * **Two reads, two scoping conventions, both deliberate.** `Message` is
     * brand-owned (`BelongsToBrand`), and a broadcast can leave from any
     * context — a console request, a queue worker, the operator admin host,
     * where `BrandScope` hardens to `WHERE 1=0` (`.ai/rules/app-modules-src.md`)
     * — so the global scope is escaped and the brand is re-applied by hand
     * from the event's own `brandId`. That is a *narrower* read than
     * `withoutBrandScope()->find()` alone, not a wider one: the row must
     * belong to the brand this event was raised for. `CommunityProfile`
     * needs neither half, because it is operator-global by design
     * (ADR-0016, ADR-0004) — no `BelongsToBrand`, therefore no `BrandScope`
     * to escape and no brand column to filter on. One line apart they look
     * asymmetric; the asymmetry is the two models', not this method's.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $message = Message::withoutBrandScope()
            ->where('brand_id', $this->brandId)
            ->find($this->messageId);

        return (new ShoutboxLineData(
            id: $this->messageId,
            roomId: $this->roomId,
            authorUserId: $this->authorUserId,
            authorDisplayName: $this->authorDisplayName(),
            body: $message?->body,
            postedAt: $this->postedAt,
        ))->toArray();
    }

    /**
     * From Community's own profile read model (ADR-0004) — Identity's user
     * is never imported. Null for an account Community has not yet heard
     * of; the client falls back to whatever it shows for a nameless author.
     *
     * `query()` with no brand escape is correct here and not an oversight:
     * `community_profiles` carries no `brand_id` and the model uses no
     * `BelongsToBrand`, because accounts are operator-global (ADR-0016). A
     * `withoutBrandScope()` on it would be escaping a scope that was never
     * applied.
     */
    private function authorDisplayName(): ?string
    {
        $name = CommunityProfile::query()->whereKey($this->authorUserId)->value('display_name');

        return is_string($name) ? $name : null;
    }
}

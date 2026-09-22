<?php

declare(strict_types=1);

namespace Konventix\Community\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Konventix\Community\Data\PostMessageData;
use Konventix\Community\Events\ShoutboxMessagePosted;
use Konventix\Community\Exceptions\ShoutboxWriteException;
use Konventix\Community\Models\Message;
use Konventix\Community\Models\Room;
use Konventix\Community\Models\RoomMute;

/**
 * Accepts one shoutbox line (spec §6, D-COM-09; ADR-0015 — an Action per
 * write).
 *
 * **Every rule here is enforced in the Action and not only in the UI**,
 * which is the point of spec §6: a client that ignores the compose box —
 * a replayed request, a script, a stale tab — must still be refused. Four
 * gates, in the order a refusal is cheapest and most honest:
 *
 * 1. a **closed** room (`EventConcluded` set `closed_at`) or a **locked**
 *    one refuses every post; a locked room stays readable (spec §6);
 * 2. a **muted** person is refused while their mute's `expires_at` is in
 *    the future **or null**, and the refusal names the reason and the
 *    remaining time (D-COM-08);
 * 3. the **500-character cap** of spec §4.2, checked here rather than left
 *    to `varchar(500)` — a column overflow is a 500, not an answer;
 * 4. **5 messages per 10 seconds, per person, per room** (D-COM-09).
 *
 * The mute gate reads Community's own `community_room_mutes` and nothing
 * else. It never looks at `community_event_presence`: that table is a
 * badge Community displays and never enforces (ADR-0067 decision 2), and
 * the access gate — whether this person may be in this room at all — is
 * Platform's `ActiveEventContext`, applied by the surface before this
 * Action is ever reached.
 *
 * The rate limit is a second axis on top of the route's `throttle:
 * shoutbox-post` (`CommunityServiceProvider`): only the Action has resolved
 * the room, so only the Action can hold a per-room budget, and only the
 * Action is reached by a caller who skipped the route.
 */
final class PostMessage
{
    /**
     * D-COM-09: 5 messages per 10 seconds, per person, per room. Enough for
     * a LAN's chatter, tight enough that a bored guest cannot flood the
     * wall. Public because `CommunityServiceProvider`'s named limiter is
     * the coarse outer half of the same budget and must not drift from it.
     */
    public const int MAX_PER_WINDOW = 5;

    public const int WINDOW_SECONDS = 10;

    /**
     * @throws ShoutboxWriteException when the room, a mute, the length or
     *                                the rate limit refuses the post
     */
    public function handle(Room $room, string $authorUserId, PostMessageData $data): Message
    {
        $now = CarbonImmutable::now()->utc();

        $this->guardRoomAcceptsPosts($room);
        $this->guardNotMuted($room, $authorUserId, $now);
        $body = $this->guardBody($data->body);
        $this->guardRate($room, $authorUserId);

        $message = DB::transaction(function () use ($room, $authorUserId, $body): Message {
            $message = new Message([
                'room_id' => $room->id,
                'author_user_id' => $authorUserId,
                'body' => $body,
            ]);

            // The room's brand, never ambient context and never mass
            // assignment (ADR-0010): the moderation console runs on the
            // operator admin host, where there is no resolved brand at all.
            $message->forceFill(['brand_id' => $room->brand_id]);
            $message->save();

            return $message;
        });

        // Dispatched **after** the commit, not inside the transaction:
        // Task 4 makes this same class a `ShouldBroadcastNow` event, and a
        // broadcast that left before the row was committed would tell a
        // client to fetch a message the next connection cannot see yet.
        event(new ShoutboxMessagePosted(
            messageId: $message->id,
            roomId: $room->id,
            brandId: $room->brand_id,
            eventId: $room->subject_id,
            authorUserId: $authorUserId,
            postedAt: $now->toIso8601String(),
        ));

        return $message;
    }

    /**
     * Closed before locked: a participant told "the wall is locked" about a
     * room that will never reopen has been told the wrong thing.
     */
    private function guardRoomAcceptsPosts(Room $room): void
    {
        if ($room->closed_at !== null) {
            throw ShoutboxWriteException::roomClosed();
        }

        if ($room->locked_at !== null) {
            throw ShoutboxWriteException::roomLocked();
        }
    }

    /**
     * The strongest mute in force, if any: `expires_at` null — "for the
     * rest of the event" (spec §4.3) — outranks any timed one, which is
     * what `NULLS FIRST` buys. `community_room_mutes` is a log rather than
     * current state, so a person may legitimately carry several rows.
     *
     * `withoutBrandScope()->where('brand_id', …)` rather than the ambient
     * scope: this Action is also reachable from paths where no brand is
     * resolved, and `BrandScope` hardens such a query to zero rows
     * (ADR-0029, `.ai/rules/app-modules-src.md`) — a mute lookup that
     * silently read nothing would let every muted person post.
     */
    private function guardNotMuted(Room $room, string $authorUserId, CarbonImmutable $now): void
    {
        $mute = RoomMute::withoutBrandScope()
            ->where('brand_id', $room->brand_id)
            ->where('room_id', $room->id)
            ->where('user_id', $authorUserId)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->orderByRaw('expires_at DESC NULLS FIRST')
            ->first();

        if ($mute instanceof RoomMute) {
            throw ShoutboxWriteException::muted($mute->reason, $mute->expires_at, $now);
        }
    }

    /**
     * Trimmed, because trailing whitespace is not a message — and a body of
     * 500 spaces would otherwise pass the cap and render as an empty line.
     */
    private function guardBody(string $body): string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            throw ShoutboxWriteException::messageEmpty();
        }

        if (mb_strlen($trimmed) > PostMessageData::MAX_BODY_LENGTH) {
            throw ShoutboxWriteException::messageTooLong(PostMessageData::MAX_BODY_LENGTH);
        }

        return $trimmed;
    }

    /**
     * The budget is spent by an attempt that has passed every other gate,
     * so a muted person hammering the endpoint does not also lock out the
     * minute after their mute lifts.
     */
    private function guardRate(Room $room, string $authorUserId): void
    {
        $key = $this->rateLimiterKey($room, $authorUserId);

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_WINDOW)) {
            throw ShoutboxWriteException::tooManyMessages(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);
    }

    private function rateLimiterKey(Room $room, string $authorUserId): string
    {
        return 'shoutbox-post:'.$room->id.':'.$authorUserId;
    }
}

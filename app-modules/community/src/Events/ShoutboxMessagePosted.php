<?php

declare(strict_types=1);

namespace Konventix\Community\Events;

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
 */
final readonly class ShoutboxMessagePosted
{
    public function __construct(
        public string $messageId,
        public string $roomId,
        public string $brandId,
        public string $eventId,
        public string $authorUserId,
        public string $postedAt,
    ) {}
}

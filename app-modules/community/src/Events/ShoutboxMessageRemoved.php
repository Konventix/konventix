<?php

declare(strict_types=1);

namespace Konventix\Community\Events;

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
 */
final readonly class ShoutboxMessageRemoved
{
    public function __construct(
        public string $messageId,
        public string $roomId,
        public string $brandId,
        public string $removedByUserId,
        public string $reason,
        public string $removedAt,
    ) {}
}

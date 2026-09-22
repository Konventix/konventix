<?php

declare(strict_types=1);

namespace Konventix\Community\Events;

/**
 * A participant reported a shoutbox line (spec §9, D-COM-01).
 *
 * Neither the reported body nor the reporter's note travels here (spec §9,
 * ADR-0004) — both are user-generated text, and an event payload is not a
 * place to copy it. A consumer that needs either reads them through
 * Community's own surface.
 *
 * Fired once per report row, which `unique(message_id, reporter_user_id)`
 * makes once per reporter per message (spec §4.4): a second attempt by the
 * same reporter is the same report, and dispatching again would put one
 * line in the desk's queue twice.
 *
 * `reportedAt` is an ISO-8601 string; see `ShoutboxMessagePosted`.
 */
final readonly class ShoutboxMessageReported
{
    public function __construct(
        public string $reportId,
        public string $messageId,
        public string $roomId,
        public string $brandId,
        public string $reporterUserId,
        public string $reportedAt,
    ) {}
}

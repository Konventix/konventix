<?php

declare(strict_types=1);

namespace Konventix\Messaging\Events;

/**
 * A delivery attempt failed (SPEC.md "Public contract"). Emitted once per
 * job attempt with `willRetry` saying whether the queue will try again —
 * the final emission (`willRetry: false`) is the one a caller alerts on.
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final class MessageFailed
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $channel,
        public readonly string $failureCode,
        public readonly int $attempt,
        public readonly bool $willRetry,
    ) {}
}

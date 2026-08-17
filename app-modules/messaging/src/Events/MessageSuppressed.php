<?php

declare(strict_types=1);

namespace Konventix\Messaging\Events;

/**
 * A send was refused before dispatch (SPEC.md "Public contract") — the
 * suppression list or the recipient's preferences declined it. This event
 * matters more than it looks: a module that believes it sent a
 * confirmation needs a way to learn the platform declined to. Payload is
 * scalars/ULIDs only (ADR-0004).
 */
final class MessageSuppressed
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $brandId,
        public readonly ?string $recipientId,
        public readonly string $templateKey,
        public readonly string $channel,
        public readonly string $reasonCode,
    ) {}
}

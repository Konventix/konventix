<?php

declare(strict_types=1);

namespace Konventix\Messaging\Events;

/**
 * A channel confirmed delivery (SPEC.md "Public contract"). "Confirmed"
 * means the transport accepted the message — for SMTP that is the strongest
 * statement available; a provider callback may refine it later but never
 * rewrites it. Payload is scalars/ULIDs only (ADR-0004).
 */
final class MessageDelivered
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $channel,
        public readonly ?string $providerMessageId,
        public readonly string $deliveredAt,
    ) {}
}

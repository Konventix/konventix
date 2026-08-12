<?php

declare(strict_types=1);

namespace Konventix\Messaging\Events;

/**
 * Fired the moment a message is accepted for delivery and handed to the
 * outbound queue — not once it is actually delivered (see MessageDelivered,
 * proposed in SPEC.md's Backlog delivery log work).
 *
 * Scalars only (ADR-0004): no Eloquent model, no Data object crosses this
 * boundary.
 */
final class MessageQueued
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $brandId,
        // Null when no account stands behind the address (e.g. a billing
        // contact) — suppression and log keying fall back to the email.
        public readonly ?string $recipientId,
        public readonly string $channel,
        public readonly string $templateKey,
    ) {}
}

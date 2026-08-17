<?php

declare(strict_types=1);

namespace Konventix\Messaging\Events;

/**
 * A suppression was lifted (SPEC.md "Public contract") — the row stays as
 * history, deliveries resume. Payload is scalars/ULIDs only (ADR-0004).
 */
final class RecipientSuppressionLifted
{
    public function __construct(
        public readonly string $suppressionId,
        public readonly string $brandId,
        public readonly ?string $recipientId,
        public readonly ?string $email,
        public readonly string $channel,
        public readonly string $liftedAt,
    ) {}
}

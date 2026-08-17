<?php

declare(strict_types=1);

namespace Konventix\Messaging\Events;

/**
 * A recipient or address joined a brand's suppression list (SPEC.md
 * "Public contract", ADR-0028: per brand, never global). Payload is
 * scalars/ULIDs only (ADR-0004).
 */
final class RecipientSuppressionAdded
{
    public function __construct(
        public readonly string $suppressionId,
        public readonly string $brandId,
        public readonly ?string $recipientId,
        public readonly ?string $email,
        public readonly string $channel,
        public readonly string $reasonCode,
        public readonly string $suppressedAt,
    ) {}
}

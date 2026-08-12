<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * An offered entry became a real registration (D-REG-12). Scalars only
 * (ADR-0004).
 */
final readonly class WaitlistEntryConverted
{
    public function __construct(
        public string $waitlistEntryId,
        public string $registrationId,
        public string $brandId,
        public string $convertedAt,
    ) {}
}

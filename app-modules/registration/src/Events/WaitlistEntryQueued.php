<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * A submission hit an exhausted quota and joined the type's waitlist
 * (D-REG-12). Scalars only (ADR-0004).
 */
final readonly class WaitlistEntryQueued
{
    public function __construct(
        public string $waitlistEntryId,
        public string $registrationTypeId,
        public string $eventId,
        public string $brandId,
        public string $registrantUserId,
        public string $queuedAt,
    ) {}
}

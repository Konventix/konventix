<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * An offer lapsed unclaimed; the held quota units were released and
 * promotion moves on to the next entry (D-REG-12). Scalars only
 * (ADR-0004).
 */
final readonly class WaitlistOfferExpired
{
    public function __construct(
        public string $waitlistEntryId,
        public string $registrationTypeId,
        public string $brandId,
        public string $expiredAt,
    ) {}
}

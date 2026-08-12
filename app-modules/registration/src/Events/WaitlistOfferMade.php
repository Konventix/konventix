<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * A freed spot was offered to the oldest queued entry; the type's quota
 * units are HELD until conversion or expiry (D-REG-12). Scalars only
 * (ADR-0004).
 */
final readonly class WaitlistOfferMade
{
    public function __construct(
        public string $waitlistEntryId,
        public string $registrationTypeId,
        public string $brandId,
        public string $registrantUserId,
        public string $offerExpiresAt,
    ) {}
}

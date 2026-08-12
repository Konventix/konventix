<?php

declare(strict_types=1);

namespace Konventix\Event\Events;

/**
 * A venue was created for a brand (SPEC.md "Public contract").
 */
final readonly class VenueCreated
{
    public function __construct(
        public string $venueId,
        public string $brandId,
        public string $name,
        public string $city,
        public string $countryCode,
    ) {}
}

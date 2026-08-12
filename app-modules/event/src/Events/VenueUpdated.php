<?php

declare(strict_types=1);

namespace Konventix\Event\Events;

/**
 * Venue address or access details changed (SPEC.md "Public contract").
 */
final readonly class VenueUpdated
{
    public function __construct(
        public string $venueId,
        public string $brandId,
        public string $name,
        public string $city,
        public string $countryCode,
    ) {}
}

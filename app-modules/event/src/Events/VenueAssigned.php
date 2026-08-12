<?php

declare(strict_types=1);

namespace Konventix\Event\Events;

/**
 * A venue was attached to an event (SPEC.md "Public contract"). D-EVT-15:
 * the payload says whether this is the event's primary venue.
 */
final readonly class VenueAssigned
{
    public function __construct(
        public string $eventId,
        public string $venueId,
        public string $brandId,
        public string $name,
        public string $city,
        public bool $isPrimary,
    ) {}
}

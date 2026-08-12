<?php

declare(strict_types=1);

namespace Konventix\Event\Events;

/**
 * A venue was detached from an event — consumers holding venue-derived
 * read models drop the association.
 */
final readonly class VenueUnassigned
{
    public function __construct(
        public string $eventId,
        public string $venueId,
        public string $brandId,
    ) {}
}

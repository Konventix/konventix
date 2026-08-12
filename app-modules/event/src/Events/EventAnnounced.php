<?php

declare(strict_types=1);

namespace Konventix\Event\Events;

/**
 * The event moved Draft → Announced: teased, not yet fully published
 * (SPEC.md "Event lifecycle").
 */
final readonly class EventAnnounced
{
    public function __construct(
        public string $eventId,
        public string $brandId,
        public string $announcedAt,
    ) {}
}

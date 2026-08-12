<?php

declare(strict_types=1);

namespace Konventix\Event\Events;

/**
 * Name, slug or description changed (SPEC.md "Public contract") — display
 * data for read models. Schedule changes emit `EventRescheduled` instead.
 */
final readonly class EventDetailsUpdated
{
    public function __construct(
        public string $eventId,
        public string $brandId,
        public string $slug,
        public string $name,
    ) {}
}

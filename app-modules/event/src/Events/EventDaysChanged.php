<?php

declare(strict_types=1);

namespace Konventix\Event\Events;

/**
 * The event's day set changed (decision log Round 6, D-ADM-04): Admission's
 * per-day validity evaluation re-projects from the full payload — it always
 * carries the complete current set, so consumers replace rather than patch.
 */
final readonly class EventDaysChanged
{
    /**
     * @param  list<array{day_id: string, date: string, sequence: int, label: string|null}>  $days
     */
    public function __construct(
        public string $eventId,
        public string $brandId,
        public array $days,
    ) {}
}

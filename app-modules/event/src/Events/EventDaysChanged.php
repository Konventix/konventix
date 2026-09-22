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
     * `day_ends_at` (`HH:MM`, D-HUB-01) arrived additively for the live hub;
     * null means 00:00 of the following day.
     *
     * @param  list<array{day_id: string, date: string, sequence: int, label: string|null, day_ends_at?: string|null}>  $days
     */
    public function __construct(
        public string $eventId,
        public string $brandId,
        public array $days,
    ) {}
}

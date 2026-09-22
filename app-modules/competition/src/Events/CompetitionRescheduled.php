<?php

declare(strict_types=1);

namespace Konventix\Competition\Events;

/**
 * A published competition's `starts_at` moved (design §12; the timetable
 * design's §4 names this event as one of the three its programme read model
 * projects). Emitted by the console's update path, never by a draft edit:
 * nothing outside this module has heard of a draft competition yet.
 *
 * `previousStartsAt` rides along so a consumer can tell "moved" from "given
 * a time for the first time" without keeping its own copy.
 */
final readonly class CompetitionRescheduled
{
    public function __construct(
        public string $competitionId,
        public string $eventId,
        public string $brandId,
        public ?string $previousStartsAt,
        public ?string $startsAt,
        public string $at,
    ) {}
}

<?php

declare(strict_types=1);

namespace Konventix\Competition\Events;

/**
 * The competition will not be played (D-CMP-15, design §4, §12). Reachable
 * from every state but `Finished`, so a consumer must be ready to hear it
 * about a competition it never saw published.
 *
 * Agenda's programme read model projects this one to strike the block from
 * the published timetable (timetable design §4).
 */
final readonly class CompetitionCancelled
{
    public function __construct(
        public string $competitionId,
        public string $eventId,
        public string $disciplineId,
        public string $brandId,
        public string $status,
        public string $at,
    ) {}
}

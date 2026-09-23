<?php

declare(strict_types=1);

namespace Konventix\Competition\Events;

/**
 * A competition was announced: its public page exists and its signup window
 * now decides whether anybody can enter (D-CMP-15, design §4, §12).
 *
 * Payload is ULIDs and scalars only (ADR-0004). `at` and `startsAt` are
 * ISO-8601 strings rather than Carbon instances for the same reason.
 *
 * Agenda projects this, `CompetitionRescheduled`, `CompetitionCancelled`
 * and `CompetitionUnpublished` into the programme's competition read model,
 * and reads `startsAt` from here rather than from this module's table — the
 * names and the field are a cross-project contract (timetable design §4).
 * `slug` (added in 0.9.1, #331) is what the programme links the block to the
 * competition's public page with (timetable design §4.5).
 */
final readonly class CompetitionPublished
{
    public function __construct(
        public string $competitionId,
        public string $eventId,
        public string $disciplineId,
        public string $brandId,
        public string $name,
        public string $slug,
        public string $status,
        public ?string $startsAt,
        public string $at,
    ) {}
}

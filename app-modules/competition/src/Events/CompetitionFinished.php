<?php

declare(strict_types=1);

namespace Konventix\Competition\Events;

/**
 * The competition is over and its standings are final (D-CMP-15, design §4,
 * §12). ULIDs and scalars only (ADR-0004).
 */
final readonly class CompetitionFinished
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

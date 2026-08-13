<?php

declare(strict_types=1);

namespace Konventix\Competition\Events;

/**
 * A team entered a competition.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — the roster is deliberately
 * absent: who is on the team is Competition's business, and a consumer that
 * needs it is asking the wrong module.
 */
final readonly class EntrantRegistered
{
    public function __construct(
        public string $entrantId,
        public string $competitionId,
        public string $competitionTeamId,
        public string $eventId,
        public string $brandId,
        public string $registeredAt,
    ) {}
}

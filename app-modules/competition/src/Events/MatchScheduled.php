<?php

declare(strict_types=1);

namespace Konventix\Competition\Events;

/**
 * A match has a time and its sides. **The contract GameOps is built against**
 * (ADR-0012): it creates a MatchSession, resolves a handler and deployment
 * strategy, provisions or claims a server, configures it, and starts the
 * match.
 *
 * The payload shape is fixed by Competition's SPEC and must not drift —
 * GameOps is written to it even though GameOps does not exist yet. That is
 * precisely why it is ULIDs and scalars only (ADR-0004), and why an
 * architecture test asserts this module imports no GameOps class: the
 * dependency runs one way, through this event.
 *
 * `gameId` and `gameMode` come from the discipline and are null for any
 * discipline that is not a video game (ADR-0024) — a quiz has no server to
 * provision, so GameOps simply never hears a usable one.
 *
 * `memberUserIds` is **additive** (design §5, #297): a map of entrant ULID to
 * the roster behind it, in the same order `entrantIds` lists the sides.
 * GameOps needs it from 0.10 onwards — a server is configured with players,
 * not with entrant ids, and resolving them would mean asking Competition,
 * which is the direction the dependency must never run. Still ULIDs only;
 * names stay behind `ParticipantProfile` in this module.
 */
final readonly class MatchScheduled
{
    /**
     * @param  list<string>  $entrantIds
     * @param  array<string, list<string>>  $memberUserIds  entrant ULID => roster, oldest member first
     */
    public function __construct(
        public string $matchId,
        public string $competitionId,
        public string $eventId,
        public string $brandId,
        public ?string $gameId,
        public ?string $gameMode,
        public int $round,
        public string $scheduledAt,
        public array $entrantIds,
        public array $memberUserIds = [],
    ) {}
}

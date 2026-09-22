<?php

declare(strict_types=1);

namespace Konventix\Competition\Events;

/**
 * The desk decided a dispute (design §8.4, §12).
 *
 * The resolution note is **not** on the payload, deliberately. §8.4 requires
 * it and it is shown to both sides, but it is free text a participant wrote
 * about other participants; a public contract event is read by modules that
 * have no business with it, and it is one row away in the match history for
 * anything that does. The payload stays ULIDs and scalars (ADR-0004).
 *
 * `userId` is the organizer who resolved it; `entrantId` is the side the
 * decision went to, or null where the desk decided a result with no winner.
 */
final readonly class MatchDisputeResolved
{
    public function __construct(
        public string $matchId,
        public string $competitionId,
        public string $brandId,
        public ?string $entrantId,
        public string $userId,
        public string $at,
    ) {}
}

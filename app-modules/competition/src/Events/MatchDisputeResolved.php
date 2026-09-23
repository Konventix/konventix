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
 * `userId` is who ended the dispute: the organizer who resolved or
 * corrected the result, or the member of a side whose confirm or agreeing
 * re-report settled the match after all (design §14, "a dispute ends when the
 * match is settled"). `entrantId` is the side the result went to, or null for
 * a result with no winner.
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

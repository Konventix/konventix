<?php

declare(strict_types=1);

namespace Konventix\Competition\Events;

/**
 * A side of a match said what the result was (design §8.2, §12).
 *
 * A *claim*, not a result: the match is not finished and nothing has been
 * recorded. `MatchFinished` is still the one event that says a match is over,
 * and it fires once (D-CMP-12).
 *
 * Not to be confused with GameOps' own proposed `MatchResultReported`
 * (`Konventix\GameOps\Events\MatchResultReported`, SPEC "Consumes"), which
 * travels the other way: a match handler telling Competition what it
 * observed. Same words, opposite direction, different class — and per
 * D-CMP-11 a handler-reported score records directly, where a participant's
 * waits for the other side.
 *
 * ULIDs and scalars only (ADR-0004).
 */
final readonly class MatchResultReported
{
    public function __construct(
        public string $matchId,
        public string $competitionId,
        public string $brandId,
        public string $entrantId,
        public string $userId,
        public string $at,
    ) {}
}

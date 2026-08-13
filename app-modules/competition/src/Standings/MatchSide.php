<?php

declare(strict_types=1);

namespace Konventix\Competition\Standings;

/**
 * One side of a finished match and what it scored.
 *
 * A null score is "not recorded", not zero — a forfeit and a nil-all draw are
 * different results, and a tiebreaker that averages them together would rank
 * them the same.
 */
final readonly class MatchSide
{
    public function __construct(
        public string $entrantId,
        public ?int $score = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Konventix\Competition\Standings;

/**
 * One row of a standings table.
 *
 * `placement` uses **standard competition ranking** (the "1224" convention):
 * entrants who cannot be separated share a placement, and the next placement
 * skips the ones consumed by the tie. Two semifinal losers with no third-place
 * match are both 3rd, and nobody is 4th.
 *
 * That is a deliberate deviation from the absorbed engine, which handed out
 * strictly increasing placements and broke every tie by seed — reporting a
 * 3rd and a 4th place that the tournament never actually played for. Seed
 * still orders the rows for display; it just no longer invents a result.
 * See `SPEC.md`.
 */
final readonly class StandingEntry
{
    public function __construct(
        public string $entrantId,
        public int $placement,
        public int $wins = 0,
        public int $losses = 0,
        public int $draws = 0,
        public int $points = 0,
        /**
         * What the format ranks on after points. Null only on a leaderboard,
         * for an entrant with no verified entry: there is no aggregate, and
         * a zero would read as a result (final review, part 1, I6).
         */
        public ?float $tiebreaker = 0.0,
        public ?string $group = null,
    ) {}

    public function played(): int
    {
        return $this->wins + $this->losses + $this->draws;
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Competition\Events;

/**
 * A match has a result. The second half of ADR-0012's contract with GameOps,
 * which tears the deployment down or returns it to its pool on hearing it.
 *
 * **Fires exactly once per match** (D-CMP-12). A correction emits
 * `MatchResultCorrected` instead, specifically so GameOps never tears the
 * same deployment down twice — the guarantee is structural, not a convention
 * consumers have to remember.
 */
final readonly class MatchFinished
{
    /**
     * @param  array<string, int|null>  $scores  entrant ULID => score
     */
    public function __construct(
        public string $matchId,
        public string $competitionId,
        public string $brandId,
        public ?string $winnerEntrantId,
        public array $scores,
        public string $finishedAt,
    ) {}
}

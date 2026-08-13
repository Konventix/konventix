<?php

declare(strict_types=1);

namespace Konventix\Competition\Events;

/**
 * The desk rewrote a recorded result (D-CMP-11, D-CMP-12).
 *
 * A distinct event rather than a second `MatchFinished`, which is the whole
 * mechanism by which that one stays once-per-match. It carries the previous
 * winner as well as the new one, because a consumer that already acted on the
 * original result needs to know what it is undoing, not just what is now true.
 */
final readonly class MatchResultCorrected
{
    /**
     * @param  array<string, int|null>  $scores  entrant ULID => score
     */
    public function __construct(
        public string $matchId,
        public string $competitionId,
        public string $brandId,
        public ?string $previousWinnerEntrantId,
        public ?string $winnerEntrantId,
        public array $scores,
        public ?string $correctedByUserId,
        public string $correctedAt,
    ) {}
}

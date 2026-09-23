<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the stage's table (design §7.2, §9 "the bracket or table").
 *
 * A leaderboard ranks on a decimal that none of wins, losses, draws or points
 * can hold, so its aggregate travels in `tiebreaker` — the column
 * `LeaderboardStandingsCalculator` writes it to.
 */
#[TypeScript]
final class DeskStandingData extends Data
{
    public function __construct(
        public readonly string $entrantId,
        public readonly string $teamName,
        public readonly ?string $group,
        public readonly ?int $placement,
        public readonly int $wins,
        public readonly int $losses,
        public readonly int $draws,
        public readonly int $points,
        public readonly ?float $tiebreaker,
    ) {}
}

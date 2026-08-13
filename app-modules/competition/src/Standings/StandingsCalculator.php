<?php

declare(strict_types=1);

namespace Konventix\Competition\Standings;

/**
 * Ranks a stage's field from its finished matches (ADR-0019).
 *
 * Pure, like the bracket generators: entrants and outcomes in, ordered
 * entries out, no database. `CalculateStandings` is the only writer.
 *
 * `key()` matches the format key in `FormatRegistry`, so a stage's `format`
 * resolves both its generator and its calculator.
 */
interface StandingsCalculator
{
    public function key(): string;

    /**
     * @param  list<StandingsEntrant>  $entrants  the full field, including entrants who have not played
     * @param  list<MatchOutcome>  $outcomes  finished matches only
     * @param  array<string, mixed>  $settings  the stage's resolved settings
     * @return list<StandingEntry> in display order, best first
     */
    public function calculate(array $entrants, array $outcomes, array $settings = []): array;
}

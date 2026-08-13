<?php

declare(strict_types=1);

namespace Konventix\Competition\Standings\Calculators;

use Konventix\Competition\Brackets\Formats\DoubleEliminationGenerator;
use Konventix\Competition\Standings\MatchOutcome;
use Konventix\Competition\Standings\StandingEntry;
use Konventix\Competition\Standings\StandingsCalculator;
use Konventix\Competition\Standings\StandingsEntrant;

/**
 * Double elimination: you finish where your **second** loss put you.
 *
 * The grand final — or its reset, if one was played — decides 1st and 2nd.
 * Everyone else is ranked by the losers-bracket round they were knocked out
 * in, later being better, with each round's losers sharing a placement.
 *
 * A winners-bracket loss eliminates nobody, so winners matches contribute
 * nothing to the ranking. That is the whole point of the format, and it is why
 * this cannot simply reuse the single-elimination calculator: the winners tree
 * decides *seeding within the losers bracket*, not finishing order.
 *
 * The bracket tags come from `DoubleEliminationGenerator`, so the two agree by
 * construction rather than by a magic round number both have to remember. The
 * absorbed engine encoded the same distinction as rounds 100, 200 and 202 —
 * sentinel values in an integer column that any query filtering "rounds 1..n"
 * would have silently swept up.
 */
final class DoubleEliminationStandingsCalculator implements StandingsCalculator
{
    public function key(): string
    {
        return 'double_elimination';
    }

    /**
     * @param  list<StandingsEntrant>  $entrants
     * @param  list<MatchOutcome>  $outcomes
     * @param  array<string, mixed>  $settings
     * @return list<StandingEntry>
     */
    public function calculate(array $entrants, array $outcomes, array $settings = []): array
    {
        $seeds = [];

        foreach ($entrants as $entrant) {
            $seeds[$entrant->id] = $entrant->seedOrLast();
        }

        $decider = $this->decidingMatch($outcomes);

        if ($decider?->winnerEntrantId === null) {
            return [];
        }

        $entries = [
            new StandingEntry(entrantId: $decider->winnerEntrantId, placement: 1, wins: 1),
        ];

        $runnerUp = $decider->loserEntrantId();

        if ($runnerUp !== null) {
            $entries[] = new StandingEntry(entrantId: $runnerUp, placement: 2, losses: 1);
        }

        $placed = [];

        foreach ($entries as $entry) {
            $placed[$entry->entrantId] = true;
        }

        // Only losers-bracket defeats eliminate.
        $byRound = [];

        foreach ($outcomes as $outcome) {
            if ($outcome->bracket !== DoubleEliminationGenerator::BRACKET_LOSERS) {
                continue;
            }

            $eliminated = $outcome->loserEntrantId();

            if ($eliminated !== null && ! isset($placed[$eliminated])) {
                $byRound[$outcome->round][] = $eliminated;
            }
        }

        krsort($byRound);

        $placement = count($entries) + 1;

        foreach ($byRound as $tier) {
            usort($tier, static fn (string $a, string $b): int => ($seeds[$a] ?? PHP_INT_MAX) <=> ($seeds[$b] ?? PHP_INT_MAX));

            foreach ($tier as $entrantId) {
                $entries[] = new StandingEntry(entrantId: $entrantId, placement: $placement, losses: 2);
            }

            $placement += count($tier);
        }

        return $entries;
    }

    /**
     * The reset if it was played, otherwise the grand final.
     *
     * @param  list<MatchOutcome>  $outcomes
     */
    private function decidingMatch(array $outcomes): ?MatchOutcome
    {
        $decider = null;

        foreach ($outcomes as $outcome) {
            if ($outcome->bracket !== DoubleEliminationGenerator::BRACKET_GRAND_FINAL) {
                continue;
            }

            if ($decider === null || $outcome->round > $decider->round) {
                $decider = $outcome;
            }
        }

        return $decider;
    }
}

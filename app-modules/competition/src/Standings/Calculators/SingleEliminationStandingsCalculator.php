<?php

declare(strict_types=1);

namespace Konventix\Competition\Standings\Calculators;

use Konventix\Competition\Standings\MatchOutcome;
use Konventix\Competition\Standings\StandingEntry;
use Konventix\Competition\Standings\StandingsCalculator;
use Konventix\Competition\Standings\StandingsEntrant;

/**
 * Single elimination: you finish where you were knocked out.
 *
 * The final's winner is 1st and its loser 2nd. Everyone else is ranked by the
 * round they lost in — later is better — and **everyone eliminated in the same
 * round shares a placement**. The two semifinal losers are both 3rd; there is
 * no 4th, because no match was played to decide which of them was worse.
 *
 * ## The deviation
 *
 * The absorbed engine handed out 3rd *and* 4th, separating the tier by seed.
 * That reports a result the tournament did not produce: a seeding is a
 * prediction made before play, and using it to award a final placement lets a
 * pre-tournament guess outrank the actual games. Seed still orders the rows so
 * a table renders deterministically — it no longer decides who beat whom.
 *
 * A brand that wants a decided 3rd place plays a third-place match, which is a
 * real match in the bracket and ranks like one.
 *
 * The tiers are powers of two by construction — 1, 1, 2, 4, 8 — which follows
 * from the bracket being a binary tree, not from any rule stated here.
 */
class SingleEliminationStandingsCalculator implements StandingsCalculator
{
    public function key(): string
    {
        return 'single_elimination';
    }

    /**
     * @param  list<StandingsEntrant>  $entrants
     * @param  list<MatchOutcome>  $outcomes
     * @param  array<string, mixed>  $settings
     * @return list<StandingEntry>
     */
    public function calculate(array $entrants, array $outcomes, array $settings = []): array
    {
        if ($outcomes === []) {
            return [];
        }

        $seeds = [];

        foreach ($entrants as $entrant) {
            $seeds[$entrant->id] = $entrant->seedOrLast();
        }

        // The stage's last round, not the last round *played*. A calculator
        // only ever sees finished matches, so without being told, a bracket
        // one round in looks exactly like a bracket that is over — and it
        // would crown whoever won the deepest match so far. `final_round`
        // comes from the stage; the fallback is the pure-call convenience.
        $finalRound = (int) ($settings['final_round']
            ?? max(array_map(static fn (MatchOutcome $o): int => $o->round, $outcomes)));

        $final = null;

        foreach ($outcomes as $outcome) {
            if ($outcome->round === $finalRound) {
                $final = $outcome;

                break;
            }
        }

        if ($final?->winnerEntrantId === null) {
            // The final has not been decided, so nothing has a final placement.
            return [];
        }

        $entries = [
            new StandingEntry(entrantId: $final->winnerEntrantId, placement: 1, wins: 1),
        ];

        $loser = $final->loserEntrantId();

        if ($loser !== null) {
            $entries[] = new StandingEntry(entrantId: $loser, placement: 2, losses: 1);
        }

        // Walk back through the rounds. Each round's losers share one tier.
        $placement = count($entries) + 1;

        for ($round = $finalRound - 1; $round >= 1; $round--) {
            $tier = [];

            foreach ($outcomes as $outcome) {
                if ($outcome->round !== $round) {
                    continue;
                }

                $eliminated = $outcome->loserEntrantId();

                if ($eliminated !== null) {
                    $tier[] = $eliminated;
                }
            }

            if ($tier === []) {
                continue;
            }

            usort($tier, static fn (string $a, string $b): int => ($seeds[$a] ?? PHP_INT_MAX) <=> ($seeds[$b] ?? PHP_INT_MAX));

            foreach ($tier as $entrantId) {
                $entries[] = new StandingEntry(entrantId: $entrantId, placement: $placement, losses: 1);
            }

            $placement += count($tier);
        }

        return $entries;
    }
}

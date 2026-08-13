<?php

declare(strict_types=1);

namespace Konventix\Competition\Standings\Calculators;

use Konventix\Competition\Standings\MatchOutcome;
use Konventix\Competition\Standings\Placements;
use Konventix\Competition\Standings\StandingEntry;
use Konventix\Competition\Standings\StandingsCalculator;
use Konventix\Competition\Standings\StandingsEntrant;

/**
 * Swiss: score first, **Buchholz** second.
 *
 * ## The tiebreaker, and why it is the one everyone uses
 *
 * In a Swiss event most entrants finish on the same score, so the score alone
 * ranks almost nobody. Buchholz (Bruno Buchholz, 1932) breaks the tie by
 * *strength of schedule*: an entrant's Buchholz is the sum of their opponents'
 * final scores. Two entrants on 4/5 are separated by who they had to beat to
 * get there, which is the only information a Swiss event actually contains —
 * it never pairs everyone against everyone, so a head-to-head table does not
 * exist to consult.
 *
 * It is computed from final scores, not scores at the time of pairing, so it
 * is only meaningful once the stage is complete. Mid-stage it is a running
 * estimate and moves as opponents keep playing; that is inherent to the
 * measure, not a defect here.
 *
 * ## Byes and the virtual opponent
 *
 * A bye scores a win against nobody. Counted naively, the entrant who took the
 * bye has one fewer opponent contributing to their Buchholz and is pushed down
 * the table for having been given a round off — punished for the organiser's
 * arithmetic. FIDE's answer is a **virtual opponent**: the bye contributes a
 * notional opponent score rather than nothing.
 *
 * Implemented here as the entrant's own score at the point of the bye, which
 * is the standard approximation. The absorbed engine had no such handling —
 * it summed real opponents only — so a bye there quietly cost a place. See
 * `SPEC.md`.
 */
final class SwissStandingsCalculator implements StandingsCalculator
{
    public function key(): string
    {
        return 'swiss';
    }

    /**
     * @param  list<StandingsEntrant>  $entrants
     * @param  list<MatchOutcome>  $outcomes
     * @param  array<string, mixed>  $settings
     * @return list<StandingEntry>
     */
    public function calculate(array $entrants, array $outcomes, array $settings = []): array
    {
        $wins = [];
        $losses = [];
        $draws = [];
        $byes = [];
        $opponents = [];
        $seeds = [];

        foreach ($entrants as $entrant) {
            $wins[$entrant->id] = 0;
            $losses[$entrant->id] = 0;
            $draws[$entrant->id] = 0;
            $byes[$entrant->id] = 0;
            $opponents[$entrant->id] = [];
            $seeds[$entrant->id] = $entrant->seedOrLast();
        }

        foreach ($outcomes as $outcome) {
            if (! $outcome->isHeadToHead()) {
                // A one-sided match is a bye: a win, but no opponent to count.
                foreach ($outcome->entrantIds() as $id) {
                    if (isset($wins[$id])) {
                        $wins[$id]++;
                        $byes[$id]++;
                    }
                }

                continue;
            }

            [$home, $away] = $outcome->sides;

            if (! isset($wins[$home->entrantId], $wins[$away->entrantId])) {
                continue;
            }

            $opponents[$home->entrantId][] = $away->entrantId;
            $opponents[$away->entrantId][] = $home->entrantId;

            if ($outcome->winnerEntrantId === null) {
                $draws[$home->entrantId]++;
                $draws[$away->entrantId]++;

                continue;
            }

            $wins[$outcome->winnerEntrantId]++;

            $loser = $outcome->loserEntrantId();

            if ($loser !== null) {
                $losses[$loser]++;
            }
        }

        // Score first, so Buchholz has something to sum.
        $score = [];

        foreach ($wins as $id => $count) {
            $score[$id] = (float) $count + ((float) $draws[$id] * 0.5);
        }

        $rows = [];

        foreach ($entrants as $entrant) {
            $buchholz = 0.0;

            foreach ($opponents[$entrant->id] as $opponentId) {
                $buchholz += $score[$opponentId] ?? 0.0;
            }

            // The virtual opponent: a bye contributes the entrant's own score
            // rather than nothing. See the class docblock.
            $buchholz += $byes[$entrant->id] * $score[$entrant->id];

            $rows[] = [
                'entrant_id' => $entrant->id,
                'seed' => $seeds[$entrant->id],
                'wins' => $wins[$entrant->id],
                'losses' => $losses[$entrant->id],
                'draws' => $draws[$entrant->id],
                'score' => $score[$entrant->id],
                'buchholz' => $buchholz,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['score'], $b['buchholz'], $a['seed']]
            <=> [$a['score'], $a['buchholz'], $b['seed']]);

        $placements = Placements::assign(
            $rows,
            static fn (array $a, array $b): bool => $a['score'] === $b['score']
                && $a['buchholz'] === $b['buchholz'],
        );

        $entries = [];

        foreach ($rows as $index => $row) {
            $entries[] = new StandingEntry(
                entrantId: $row['entrant_id'],
                placement: $placements[$index],
                wins: $row['wins'],
                losses: $row['losses'],
                draws: $row['draws'],
                // Swiss scores in wins, so points and wins coincide unless the
                // stage allows draws — where a half point has nowhere to go in
                // an integer column. `tiebreaker` carries the exact score.
                points: (int) $row['score'],
                tiebreaker: $row['buchholz'],
            );
        }

        return $entries;
    }

    /**
     * The matchup keys a Swiss stage has already played, for `pairNextRound()`.
     *
     * @param  list<MatchOutcome>  $outcomes
     * @return list<string>
     */
    public static function playedMatchups(array $outcomes): array
    {
        $keys = [];

        foreach ($outcomes as $outcome) {
            if (! $outcome->isHeadToHead()) {
                continue;
            }

            [$home, $away] = $outcome->sides;

            $keys[] = $home->entrantId < $away->entrantId
                ? $home->entrantId.'|'.$away->entrantId
                : $away->entrantId.'|'.$home->entrantId;
        }

        return array_values(array_unique($keys));
    }
}

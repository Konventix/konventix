<?php

declare(strict_types=1);

namespace Konventix\Competition\Standings\Calculators;

use Konventix\Competition\Standings\LeagueRecord;
use Konventix\Competition\Standings\MatchOutcome;
use Konventix\Competition\Standings\Placements;
use Konventix\Competition\Standings\StandingEntry;
use Konventix\Competition\Standings\StandingsCalculator;
use Konventix\Competition\Standings\StandingsEntrant;

/**
 * League table: points for results, score difference to break ties.
 *
 * The ranking rule every round robin uses — `points_win` / `points_draw` /
 * `points_loss` from the stage settings, then goal difference, then the seed.
 * Nothing here knows what is being played (ADR-0024): a football group and a
 * best-of-three shooter group rank identically, because the abstraction the
 * absorbed engine settled on is points-and-difference, not sport.
 *
 * The whole field is ranked, including entrants who have not played. An
 * entrant on zero from zero belongs at the bottom of the table, not absent
 * from it — a table that silently omits people is how a withdrawn entrant
 * becomes invisible instead of last.
 */
class PointsStandingsCalculator implements StandingsCalculator
{
    public function key(): string
    {
        return 'round_robin';
    }

    /**
     * @param  list<StandingsEntrant>  $entrants
     * @param  list<MatchOutcome>  $outcomes
     * @param  array<string, mixed>  $settings
     * @return list<StandingEntry>
     */
    public function calculate(array $entrants, array $outcomes, array $settings = []): array
    {
        return $this->rank($this->tally($entrants, $outcomes, $settings), null);
    }

    /**
     * Wins, losses, draws, points and score difference per entrant.
     *
     * @param  list<StandingsEntrant>  $entrants
     * @param  list<MatchOutcome>  $outcomes
     * @param  array<string, mixed>  $settings
     * @return array<string, LeagueRecord>
     */
    final protected function tally(array $entrants, array $outcomes, array $settings): array
    {
        $pointsWin = (int) ($settings['points_win'] ?? 3);
        $pointsDraw = (int) ($settings['points_draw'] ?? 1);
        $pointsLoss = (int) ($settings['points_loss'] ?? 0);

        $tally = [];

        foreach ($entrants as $entrant) {
            $tally[$entrant->id] = new LeagueRecord($entrant->id, $entrant->seedOrLast());
        }

        foreach ($outcomes as $outcome) {
            if (! $outcome->isHeadToHead()) {
                continue;
            }

            [$home, $away] = $outcome->sides;

            if (! isset($tally[$home->entrantId], $tally[$away->entrantId])) {
                // An entrant who has since been removed from the field. Their
                // matches stay in history; they just no longer rank.
                continue;
            }

            $tally[$home->entrantId]->difference += ($home->score ?? 0) - ($away->score ?? 0);
            $tally[$away->entrantId]->difference += ($away->score ?? 0) - ($home->score ?? 0);

            if ($outcome->winnerEntrantId === null) {
                foreach ([$home->entrantId, $away->entrantId] as $id) {
                    $tally[$id]->draws++;
                    $tally[$id]->points += $pointsDraw;
                }

                continue;
            }

            $winner = $tally[$outcome->winnerEntrantId] ?? null;
            $loser = $outcome->loserEntrantId();

            if ($winner !== null) {
                $winner->wins++;
                $winner->points += $pointsWin;
            }

            if ($loser !== null) {
                $tally[$loser]->losses++;
                $tally[$loser]->points += $pointsLoss;
            }
        }

        return $tally;
    }

    /**
     * Sorts a tally and turns it into placed entries.
     *
     * @param  array<string, LeagueRecord>  $tally
     * @return list<StandingEntry>
     */
    final protected function rank(array $tally, ?string $group): array
    {
        $rows = array_values($tally);

        usort($rows, static fn (LeagueRecord $a, LeagueRecord $b): int => [$b->points, $b->difference, $a->seed]
            <=> [$a->points, $a->difference, $b->seed]);

        // Seed orders the rows but never separates them: two entrants level on
        // points and difference finished level, whatever they were seeded.
        $placements = Placements::assign(
            $rows,
            static fn (LeagueRecord $a, LeagueRecord $b): bool => $a->ties($b),
        );

        $entries = [];

        foreach ($rows as $index => $row) {
            $entries[] = new StandingEntry(
                entrantId: $row->entrantId,
                placement: $placements[$index],
                wins: $row->wins,
                losses: $row->losses,
                draws: $row->draws,
                points: $row->points,
                tiebreaker: (float) $row->difference,
                group: $group,
            );
        }

        return $entries;
    }
}

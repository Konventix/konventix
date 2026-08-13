<?php

declare(strict_types=1);

namespace Konventix\Competition\Standings\Calculators;

use Konventix\Competition\Standings\MatchOutcome;
use Konventix\Competition\Standings\StandingEntry;
use Konventix\Competition\Standings\StandingsEntrant;

/**
 * A league table per group.
 *
 * Groups rank independently — a group stage produces `g` tables, not one — so
 * every group has its own 1st. Ranking across groups is not a standings
 * question but a qualification one: which group winners advance, and how the
 * best runners-up are compared, is the playoff stage's business.
 *
 * Group membership comes from the match's `bracket` tag (`group-A`), which is
 * what `GroupStageGenerator` writes. An entrant is in the group they played
 * in; nothing has to store the draw a second time and risk the two disagreeing.
 */
final class GroupStandingsCalculator extends PointsStandingsCalculator
{
    public function key(): string
    {
        return 'group_stage';
    }

    /**
     * @param  list<StandingsEntrant>  $entrants
     * @param  list<MatchOutcome>  $outcomes
     * @param  array<string, mixed>  $settings
     * @return list<StandingEntry>
     */
    public function calculate(array $entrants, array $outcomes, array $settings = []): array
    {
        $byGroup = [];

        foreach ($outcomes as $outcome) {
            $byGroup[$outcome->bracket ?? ''][] = $outcome;
        }

        ksort($byGroup);

        $entries = [];

        foreach ($byGroup as $group => $groupOutcomes) {
            // Only the entrants who played in this group belong in its table.
            $played = [];

            foreach ($groupOutcomes as $outcome) {
                foreach ($outcome->entrantIds() as $id) {
                    $played[$id] = true;
                }
            }

            $members = array_values(array_filter(
                $entrants,
                static fn ($entrant): bool => isset($played[$entrant->id]),
            ));

            if ($members === []) {
                continue;
            }

            $entries = [...$entries, ...$this->rank(
                $this->tally($members, $groupOutcomes, $settings),
                $group === '' ? null : (string) $group,
            )];
        }

        return $entries;
    }
}

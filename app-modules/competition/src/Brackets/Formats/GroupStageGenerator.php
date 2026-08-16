<?php

declare(strict_types=1);

namespace Konventix\Competition\Brackets\Formats;

use Konventix\Competition\Brackets\FormatGenerator;
use Konventix\Competition\Brackets\Plan\BracketPlan;
use Konventix\Competition\Brackets\Plan\PlannedMatch;

/**
 * Group stage: serpentine distribution, then a round robin inside each group.
 *
 * ## The mathematics
 *
 * Splitting a seeded field into `g` groups fairly is a *balanced partition*
 * problem: every group should have comparable total strength. **Serpentine**
 * (boustrophedon) distribution is the standard answer — deal seeds
 * left-to-right, then right-to-left, and repeat. Group `i` receives seeds
 * `i+1`, `2g-i`, `2g+i+1`, … so each group gets one seed from every band of
 * `g`, and the sums of seed numbers differ by at most `g-1`. Simple sequential
 * dealing, by contrast, would put seeds 1–4 in one group and 5–8 in another,
 * which is not a draw so much as a sorting.
 *
 * Each group then plays the circle method (see `RoundRobinGenerator`), so
 * within a group every pair still meets exactly once.
 *
 * ## A quirk faithfully preserved
 *
 * LanBrackets' fill does not advance the index on the iteration where it
 * reverses direction, so each boundary group receives two consecutive seeds.
 * For two groups that produces A(1), B(2), B(3), A(4), A(5)… — the classic
 * snake. Reproducing it exactly rather than writing a "cleaner" reverse keeps
 * seed placement identical to the predecessor (ADR-0019), which is what makes
 * a migrated tournament read the same. A tidier loop would silently redraw
 * every existing group.
 */
final class GroupStageGenerator implements FormatGenerator
{
    public function key(): string
    {
        return 'group_stage';
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'best_of' => 1,
            'group_size' => 4,
            'group_count' => null,
            'points_win' => 3,
            'points_draw' => 1,
            'points_loss' => 0,
            'allow_draws' => true,
        ];
    }

    /**
     * @param  list<string>  $entrantIds
     * @param  array<string, mixed>  $settings
     */
    public function generate(array $entrantIds, array $settings = []): BracketPlan
    {
        if (count($entrantIds) < 4) {
            return new BracketPlan;
        }

        $groups = $this->distribute($entrantIds, $this->groupCount($entrantIds, $settings));

        $roundRobin = new RoundRobinGenerator;
        $matches = [];
        $byes = [];

        foreach ($groups as $groupIndex => $members) {
            if (count($members) < 2) {
                continue;
            }

            $plan = $roundRobin->generate($members, $settings);
            $label = $this->groupLabel($groupIndex);

            foreach ($plan->matches as $match) {
                // Re-reffed and tagged per group: rounds run in parallel
                // across groups, so round 1 exists once per group.
                $matches[] = new PlannedMatch(
                    ref: sprintf('gs-%s-%s', $label, $match->ref),
                    round: $match->round,
                    position: $match->position,
                    entrantIds: $match->entrantIds,
                    bracket: 'group-'.$label,
                );
            }

            $byes = array_merge($byes, $plan->byeEntrantIds);
        }

        return new BracketPlan(matches: $matches, byeEntrantIds: $byes);
    }

    /**
     * Serpentine fill. See the class docblock for why the boundary behaviour
     * is what it is.
     *
     * @param  list<string>  $entrantIds
     * @return list<list<string>>
     */
    public function distribute(array $entrantIds, int $groupCount): array
    {
        /** @var list<list<string>> $groups */
        $groups = array_fill(0, $groupCount, []);

        $index = 0;
        $direction = 1;

        foreach ($entrantIds as $entrantId) {
            $groups[$index][] = $entrantId;

            $next = $index + $direction;

            // At a boundary the direction flips and the index deliberately
            // stays put, so the edge group takes two seeds in a row.
            if ($next >= $groupCount || $next < 0) {
                $direction *= -1;
            } else {
                $index = $next;
            }
        }

        return $groups;
    }

    /**
     * @param  list<string>  $entrantIds
     * @param  array<string, mixed>  $settings
     */
    private function groupCount(array $entrantIds, array $settings): int
    {
        $explicit = $settings['group_count'] ?? null;

        if (is_int($explicit) && $explicit >= 2) {
            return $explicit;
        }

        $size = $settings['group_size'] ?? $this->defaults()['group_size'];

        // At least two groups, or it is a round robin wearing a costume.
        return max(2, (int) ceil(count($entrantIds) / max(2, (int) $size)));
    }

    /**
     * Spreadsheet-column labels: A…Z, then AA, AB, … — chr(65 + $index)
     * alone leaves the alphabet at the 27th group ('[').
     *
     * @param  non-negative-int  $index
     */
    private function groupLabel(int $index): string
    {
        $label = '';

        do {
            $label = chr(65 + ($index % 26)).$label;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $label;
    }
}

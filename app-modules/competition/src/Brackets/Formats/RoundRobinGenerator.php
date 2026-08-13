<?php

declare(strict_types=1);

namespace Konventix\Competition\Brackets\Formats;

use Konventix\Competition\Brackets\FormatGenerator;
use Konventix\Competition\Brackets\Plan\BracketPlan;
use Konventix\Competition\Brackets\Plan\PlannedMatch;

/**
 * Round robin by the **circle method** (Kirkman, 1847).
 *
 * ## The mathematics
 *
 * Scheduling a round robin is exactly the problem of decomposing the complete
 * graph `K_n` into perfect matchings — a *1-factorization*. Each round is one
 * perfect matching (everybody paired, nobody twice); the schedule is complete
 * when every edge of `K_n` has been used exactly once.
 *
 * `K_{2m}` is 1-factorable, and the circle method is the constructive proof:
 * fix one vertex, arrange the other `2m-1` in a circle, and rotate. It yields
 * exactly `2m-1` rounds of `m` matches — which is optimal, since each round
 * can retire at most `m` of the `C(2m, 2) = m(2m-1)` edges.
 *
 * For odd `n` a virtual entrant is added to make the count even; whoever is
 * paired with it sits that round out. Each real entrant then draws exactly one
 * bye across `n` rounds.
 *
 * ## Why this is worth stating
 *
 * The completeness guarantee is *structural*, not tested-into-existence: every
 * pair meets exactly once because the construction is a proof, not because a
 * fixture happens to cover it. `RoundRobinGeneratorTest` asserts the property
 * exhaustively anyway, which is how a port earns trust.
 *
 * The rotation direction here — pop the last of the rotating slice, unshift it
 * to the front — is LanBrackets' (ADR-0019). Any rotation produces a valid
 * 1-factorization; keeping this one keeps fixture *ordering* identical to the
 * predecessor, so a migrated tournament reads the same way.
 */
final class RoundRobinGenerator implements FormatGenerator
{
    public function key(): string
    {
        return 'round_robin';
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'best_of' => 1,
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
        if (count($entrantIds) < 2) {
            return new BracketPlan;
        }

        $byes = [];

        // Odd counts get a virtual entrant so the circle has an even size;
        // whoever draws it sits the round out.
        $circle = count($entrantIds) % 2 === 0
            ? $entrantIds
            : [...$entrantIds, null];

        $size = count($circle);
        $rounds = $size - 1;
        $perRound = intdiv($size, 2);

        $matches = [];

        for ($round = 1; $round <= $rounds; $round++) {
            $position = 0;

            for ($i = 0; $i < $perRound; $i++) {
                // Mirrored pairing across the circle: position i meets
                // position size-1-i. This, plus the rotation below, is what
                // makes every pair meet exactly once.
                $home = $circle[$i];
                $away = $circle[$size - 1 - $i];

                if ($home === null && $away === null) {
                    continue;
                }

                // A pairing against the virtual entrant is a bye: recorded so
                // standings can credit it, but no match is played.
                if ($home === null || $away === null) {
                    $byes[] = $home ?? $away;

                    continue;
                }

                $position++;

                $matches[] = new PlannedMatch(
                    ref: sprintf('rr-r%d-p%d', $round, $position),
                    round: $round,
                    position: $position,
                    entrantIds: [1 => $home, 2 => $away],
                );
            }

            $circle = $this->rotate($circle);
        }

        // Round robin has no progression graph: no match feeds another, which
        // is why standings rather than a bracket decide the outcome.
        return new BracketPlan(matches: $matches, byeEntrantIds: array_values(array_filter($byes)));
    }

    /**
     * One step of the circle: the first position is fixed, the rest rotate.
     *
     * Fixing one vertex is what makes the construction work — without it the
     * rotation would map the schedule onto itself and repeat pairings.
     *
     * @param  list<string|null>  $circle
     * @return list<string|null>
     */
    private function rotate(array $circle): array
    {
        $fixed = $circle[0];
        $rotating = array_slice($circle, 1);

        $last = array_pop($rotating);
        array_unshift($rotating, $last);

        return array_merge([$fixed], $rotating);
    }
}

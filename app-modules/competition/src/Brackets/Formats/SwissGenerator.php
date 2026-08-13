<?php

declare(strict_types=1);

namespace Konventix\Competition\Brackets\Formats;

use Konventix\Competition\Brackets\FormatGenerator;
use Konventix\Competition\Brackets\Plan\BracketPlan;
use Konventix\Competition\Brackets\Plan\PlannedMatch;

/**
 * Swiss pairing.
 *
 * ## The mathematics, and where this implementation sits relative to it
 *
 * The *correct* formulation of a Swiss round is a **minimum-weight perfect
 * matching** on a graph whose vertices are the remaining entrants and whose
 * edge weights punish what you want to avoid: pairing entrants on different
 * scores, and rematches (weight ∞, or large enough to dominate). Edmonds'
 * blossom algorithm (1965) solves that exactly in polynomial time, so an
 * optimal pairing is computable rather than merely approachable. FIDE's Dutch
 * system is a rule-based specialisation of the same idea.
 *
 * **This implementation is not that.** Ported from LanBrackets per ADR-0019,
 * it is a *greedy first-fit*: walk the standings-sorted list, and pair each
 * entrant with the first later entrant they have not already played. That is
 * a heuristic, and it has a known failure mode — greedy first-fit can paint
 * itself into a corner, leaving the last two entrants as a forced rematch even
 * when a rematch-free pairing of the whole field existed. The code then falls
 * back to pairing them anyway.
 *
 * This is documented rather than quietly fixed because it is a real
 * behavioural property of the absorbed engine, and swapping in a blossom
 * matching is a change of tournament behaviour, not a refactor: it would pair
 * some rounds differently from the predecessor for the same input.
 * `SPEC.md` records it as a known limitation with the proven alternative
 * named, so the choice is a decision rather than an accident.
 *
 * Round count is `ceil(log2(n))`, the number of rounds needed for a single
 * undefeated entrant to be possible — with `2^r` entrants, `r` rounds halve
 * the undefeated field to one.
 */
final class SwissGenerator implements FormatGenerator
{
    public function key(): string
    {
        return 'swiss';
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'best_of' => 1,
            // Null means "derive it": ceil(log2(n)).
            'total_rounds' => null,
            // Swiss has no draws in this engine; the resolver rejects ties.
            'allow_draws' => false,
            'tiebreaker' => 'buchholz',
        ];
    }

    /**
     * Rounds needed for exactly one entrant to be able to finish undefeated.
     */
    public static function roundsFor(int $entrantCount): int
    {
        return $entrantCount < 2 ? 0 : (int) ceil(log($entrantCount, 2));
    }

    /**
     * Only round one is planned up front. Every later round depends on results
     * that do not exist yet — which is the whole point of Swiss, and why this
     * generator returns one round rather than a full bracket.
     *
     * @param  list<string>  $entrantIds
     * @param  array<string, mixed>  $settings
     */
    public function generate(array $entrantIds, array $settings = []): BracketPlan
    {
        if (count($entrantIds) < 2) {
            return new BracketPlan;
        }

        // Round one has no standings, so seed order is the only information
        // available: 1 plays 2, 3 plays 4. An odd field byes the lowest seed.
        $odd = count($entrantIds) % 2 !== 0;

        return new BracketPlan(
            matches: $this->toMatches($odd ? array_slice($entrantIds, 0, -1) : $entrantIds, 1),
            byeEntrantIds: $odd ? [$entrantIds[count($entrantIds) - 1]] : [],
        );
    }

    /**
     * Pairs a later round from standings order, avoiding rematches where the
     * greedy pass can.
     *
     * Returns a `BracketPlan` rather than a bare match list because an odd
     * field leaves someone unpaired, and *who* is not derivable from the
     * standings order alone — the greedy walk decides it.
     *
     * @param  list<string>  $standingsOrder  best-placed first
     * @param  list<string>  $alreadyPlayed  "idA|idB" keys, ids sorted
     */
    public function pairNextRound(array $standingsOrder, array $alreadyPlayed, int $round): BracketPlan
    {
        $remaining = $standingsOrder;
        $ordered = [];

        while (count($remaining) > 1) {
            $current = array_shift($remaining);

            $opponentIndex = null;

            foreach ($remaining as $index => $candidate) {
                if (! in_array($this->matchupKey($current, $candidate), $alreadyPlayed, true)) {
                    $opponentIndex = $index;

                    break;
                }
            }

            // Everyone left has already played them. Greedy first-fit has no
            // way back from here, so the rematch is accepted — see the class
            // docblock and SPEC.md's "known limitation".
            $opponentIndex ??= 0;

            $opponent = $remaining[$opponentIndex];
            unset($remaining[$opponentIndex]);
            $remaining = array_values($remaining);

            $ordered[] = $current;
            $ordered[] = $opponent;
        }

        return new BracketPlan(
            matches: $this->toMatches($ordered, $round),
            byeEntrantIds: $remaining,
        );
    }

    /**
     * @param  list<string>  $ordered  consecutive pairs
     * @return list<PlannedMatch>
     */
    private function toMatches(array $ordered, int $round): array
    {
        $matches = [];
        $position = 0;

        for ($i = 0; $i + 1 < count($ordered); $i += 2) {
            $position++;

            $matches[] = new PlannedMatch(
                ref: sprintf('sw-r%d-p%d', $round, $position),
                round: $round,
                position: $position,
                entrantIds: [1 => $ordered[$i], 2 => $ordered[$i + 1]],
            );
        }

        return $matches;
    }

    public function matchupKey(string $a, string $b): string
    {
        return $a < $b ? $a.'|'.$b : $b.'|'.$a;
    }
}

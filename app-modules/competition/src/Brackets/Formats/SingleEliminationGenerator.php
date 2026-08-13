<?php

declare(strict_types=1);

namespace Konventix\Competition\Brackets\Formats;

use Konventix\Competition\Brackets\FormatGenerator;
use Konventix\Competition\Brackets\Plan\BracketPlan;
use Konventix\Competition\Brackets\Plan\PlannedConnection;
use Konventix\Competition\Brackets\Plan\PlannedMatch;
use Konventix\Competition\Models\MatchConnection;

/**
 * Single elimination with **standard (recursive) seeding**.
 *
 * ## The mathematics
 *
 * A bracket of `2^k` slots is filled by the recursively-defined seeding
 * permutation:
 *
 *     S(1)  = [1]
 *     S(2n) = for each s in S(n): s, then (2n + 1 - s)
 *
 * giving `S(2) = [1,2]`, `S(4) = [1,4,2,3]`, `S(8) = [1,8,4,5,2,7,3,6]`.
 * Taking that list two at a time gives round one. (It is the bit-reversal
 * permutation in disguise — slot `i` holds a seed determined by reversing the
 * bits of `i`.)
 *
 * Two properties follow, and they are the reason this construction is used
 * everywhere rather than being one option among many:
 *
 * 1. **Every round-one pairing sums to `bracketSize + 1`** — 1 plays 16,
 *    2 plays 15, and so on. Each match is as lopsided as the field allows,
 *    which is what a seeding is *for*.
 * 2. **Seeds meet as late as the bracket permits.** Seeds 1 and 2 can only
 *    meet in the final, 1 and 4 no earlier than the semi-final, and in
 *    general seeds `i` and `j` cannot meet before round
 *    `k - floor(log2(max(i,j) - 1))`. No other arrangement delays every top
 *    pairing simultaneously.
 *
 * ## Byes
 *
 * A field of `n` is played in a bracket of `2^ceil(log2 n)`, leaving
 * `2^ceil(log2 n) - n` byes. Because seeding pairs `s` against
 * `bracketSize + 1 - s`, the missing opponents are precisely the highest seed
 * numbers — so byes land on the **top seeds automatically**, with no separate
 * bye-assignment rule to get wrong. That is a consequence of the construction,
 * not a patch on top of it.
 */
final class SingleEliminationGenerator implements FormatGenerator
{
    public function key(): string
    {
        return 'single_elimination';
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return ['best_of' => 1, 'allow_draws' => false];
    }

    /**
     * The seeding permutation for a bracket of `$size` slots.
     *
     * @return list<int> 1-based seed numbers in slot order
     */
    public static function seedOrder(int $size): array
    {
        $order = [1];

        while (count($order) < $size) {
            $next = count($order) * 2;
            $expanded = [];

            foreach ($order as $seed) {
                $expanded[] = $seed;
                $expanded[] = $next + 1 - $seed;
            }

            $order = $expanded;
        }

        return $order;
    }

    /** The smallest power of two that fits the field. */
    public static function bracketSize(int $entrantCount): int
    {
        return $entrantCount < 2 ? 0 : 2 ** (int) ceil(log($entrantCount, 2));
    }

    /**
     * @param  list<string>  $entrantIds  seed order: index 0 is seed 1
     * @param  array<string, mixed>  $settings
     */
    public function generate(array $entrantIds, array $settings = []): BracketPlan
    {
        $count = count($entrantIds);

        if ($count < 2) {
            return new BracketPlan;
        }

        $size = self::bracketSize($count);
        $order = self::seedOrder($size);

        $matches = [];
        $connections = [];
        $byes = [];

        // Round one: consecutive pairs of the seeding permutation.
        $position = 0;

        for ($i = 0; $i < $size; $i += 2) {
            $position++;

            $homeSeed = $order[$i];
            $awaySeed = $order[$i + 1];

            // A seed number beyond the field is an absent opponent — the bye
            // the seeding produces on its own.
            $home = $homeSeed <= $count ? $entrantIds[$homeSeed - 1] : null;
            $away = $awaySeed <= $count ? $entrantIds[$awaySeed - 1] : null;

            if ($home !== null && $away === null) {
                $byes[] = $home;
            } elseif ($away !== null && $home === null) {
                $byes[] = $away;
            }

            $matches[] = new PlannedMatch(
                ref: $this->ref(1, $position),
                round: 1,
                position: $position,
                entrantIds: [1 => $home, 2 => $away],
            );
        }

        // Later rounds: half as many matches each, until one remains.
        $rounds = (int) log($size, 2);

        for ($round = 2; $round <= $rounds; $round++) {
            $inRound = intdiv($size, 2 ** $round);

            for ($position = 1; $position <= $inRound; $position++) {
                $matches[] = new PlannedMatch(
                    ref: $this->ref($round, $position),
                    round: $round,
                    position: $position,
                    entrantIds: [1 => null, 2 => null],
                );
            }
        }

        // The progression graph. Matches `2p-1` and `2p` of a round feed slots
        // 1 and 2 of match `p` in the next — the arithmetic that makes a
        // bracket a binary tree.
        for ($round = 1; $round < $rounds; $round++) {
            $inRound = intdiv($size, 2 ** $round);

            for ($position = 1; $position <= $inRound; $position++) {
                $connections[] = new PlannedConnection(
                    sourceRef: $this->ref($round, $position),
                    targetRef: $this->ref($round + 1, intdiv($position + 1, 2)),
                    sourceOutcome: MatchConnection::OUTCOME_WINNER,
                    targetSlot: $position % 2 === 1 ? 1 : 2,
                );
            }
        }

        return new BracketPlan($matches, $connections, $byes);
    }

    private function ref(int $round, int $position): string
    {
        return sprintf('se-r%d-p%d', $round, $position);
    }
}

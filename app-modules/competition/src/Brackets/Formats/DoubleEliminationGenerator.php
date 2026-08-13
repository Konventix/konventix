<?php

declare(strict_types=1);

namespace Konventix\Competition\Brackets\Formats;

use Konventix\Competition\Brackets\FormatGenerator;
use Konventix\Competition\Brackets\Plan\BracketPlan;
use Konventix\Competition\Brackets\Plan\PlannedConnection;
use Konventix\Competition\Brackets\Plan\PlannedMatch;
use Konventix\Competition\Models\MatchConnection;

/**
 * Double elimination: a winners bracket, a losers bracket, and a grand final.
 *
 * ## The structure, and why it is the size it is
 *
 * With `2^k` entrants the winners bracket is a standard single-elimination
 * tree of `k` rounds. Everyone eliminated from it — `2^k - 1` entrants — gets
 * one more life, so the losers bracket must absorb a stream of arrivals while
 * halving its own field. That forces its shape:
 *
 * - **`2(k-1)` losers rounds**, alternating *minor* rounds (LB survivors play
 *   each other, halving the field) and *major* rounds (LB survivors meet the
 *   fresh drop-ins from the winners bracket, keeping the count level).
 * - Losers round 1 pairs the `2^(k-1)` first-round losers among themselves.
 * - Losers from winners round `r > 1` drop into losers round `2(r-1)`.
 *
 * A double-elimination event of `n` needs `2n - 2` decisive matches — each of
 * `n - 1` non-winners must lose twice — plus one more if the grand final is
 * reset.
 *
 * ## Rematch crossover — a deliberate deviation from the absorbed engine
 *
 * When winners-round losers drop in, *which* losers match each one lands in
 * matters. Feeding drop-in `i` into losers match `i` lets two entrants who
 * just played in the winners bracket meet again immediately in the losers
 * bracket — an anticlimax, and the reason every mature bracket generator
 * applies a **crossover**: the drop-in order is reversed (or rotated) on
 * alternate rounds so a loser lands as far from their recent opponents as the
 * structure allows.
 *
 * LanBrackets has **no** crossover — verified by reading the generator, which
 * wires drop-in `i` to losers match `i` verbatim, and confirmed by the absence
 * of any rematch assertion in its tests. Reversing on alternate rounds is
 * implemented here instead, because "absorb the engine" (ADR-0019) is not a
 * mandate to absorb a known weakness, and the deviation is visible in the
 * seeding of every event rather than hidden.
 *
 * The honest claim: reversal *reduces* immediate rematches, it does not
 * eliminate them. Guaranteeing a rematch-free losers bracket for every field
 * size is not possible by reordering alone — see `SPEC.md`.
 */
final class DoubleEliminationGenerator implements FormatGenerator
{
    public const string BRACKET_WINNERS = 'winners';

    public const string BRACKET_LOSERS = 'losers';

    public const string BRACKET_GRAND_FINAL = 'grand_final';

    public function key(): string
    {
        return 'double_elimination';
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return ['best_of' => 1, 'allow_draws' => false, 'grand_final_reset' => true];
    }

    /**
     * @param  list<string>  $entrantIds
     * @param  array<string, mixed>  $settings
     */
    public function generate(array $entrantIds, array $settings = []): BracketPlan
    {
        if (count($entrantIds) < 3) {
            return new BracketPlan;
        }

        // The winners bracket is a single-elimination bracket, seeded by the
        // proven construction — so double elimination inherits its guarantees
        // rather than restating them.
        $winners = (new SingleEliminationGenerator)->generate($entrantIds, $settings);

        $size = SingleEliminationGenerator::bracketSize(count($entrantIds));
        $wbRounds = (int) log($size, 2);

        $matches = [];
        $connections = [];

        foreach ($winners->matches as $match) {
            $matches[] = new PlannedMatch(
                ref: $match->ref,
                round: $match->round,
                position: $match->position,
                entrantIds: $match->entrantIds,
                bracket: self::BRACKET_WINNERS,
            );
        }

        $connections = $winners->connections;

        // --- losers bracket -------------------------------------------------
        $lbRounds = ($wbRounds - 1) * 2;

        for ($lbRound = 1; $lbRound <= $lbRounds; $lbRound++) {
            foreach (range(1, $this->losersRoundSize($size, $lbRound)) as $position) {
                $matches[] = new PlannedMatch(
                    ref: $this->lbRef($lbRound, $position),
                    round: $lbRound,
                    position: $position,
                    entrantIds: [1 => null, 2 => null],
                    bracket: self::BRACKET_LOSERS,
                );
            }
        }

        // Winners round 1 losers pair up inside losers round 1.
        $lbRound1 = $this->losersRoundSize($size, 1);

        for ($position = 1; $position <= $lbRound1; $position++) {
            foreach ([1, 2] as $slot) {
                $sourcePosition = ($position - 1) * 2 + $slot;

                $connections[] = new PlannedConnection(
                    sourceRef: sprintf('se-r1-p%d', $sourcePosition),
                    targetRef: $this->lbRef(1, $position),
                    sourceOutcome: MatchConnection::OUTCOME_LOSER,
                    targetSlot: $slot,
                );
            }
        }

        // Later winners-round losers drop into the major losers rounds.
        for ($wbRound = 2; $wbRound <= $wbRounds; $wbRound++) {
            $lbTarget = 2 * ($wbRound - 1);
            $count = $this->losersRoundSize($size, $lbTarget);

            for ($i = 0; $i < $count; $i++) {
                // The crossover: reverse the drop-in order on alternate
                // rounds so a loser lands away from whoever just beat them.
                $sourcePosition = $wbRound % 2 === 0 ? $count - $i : $i + 1;

                $connections[] = new PlannedConnection(
                    sourceRef: sprintf('se-r%d-p%d', $wbRound, $sourcePosition),
                    targetRef: $this->lbRef($lbTarget, $i + 1),
                    sourceOutcome: MatchConnection::OUTCOME_LOSER,
                    targetSlot: 2,
                );
            }
        }

        // Losers-bracket internal progression.
        for ($lbRound = 1; $lbRound < $lbRounds; $lbRound++) {
            $from = $this->losersRoundSize($size, $lbRound);
            $to = $this->losersRoundSize($size, $lbRound + 1);

            if ($from === $to) {
                // Minor → major: the survivor holds slot 1 and waits for a
                // drop-in.
                for ($position = 1; $position <= $from; $position++) {
                    $connections[] = new PlannedConnection(
                        sourceRef: $this->lbRef($lbRound, $position),
                        targetRef: $this->lbRef($lbRound + 1, $position),
                        sourceOutcome: MatchConnection::OUTCOME_WINNER,
                        targetSlot: 1,
                    );
                }

                continue;
            }

            // Major → minor: pairs of survivors combine, halving the field.
            for ($position = 1; $position <= $to; $position++) {
                foreach ([1, 2] as $slot) {
                    $connections[] = new PlannedConnection(
                        sourceRef: $this->lbRef($lbRound, ($position - 1) * 2 + $slot),
                        targetRef: $this->lbRef($lbRound + 1, $position),
                        sourceOutcome: MatchConnection::OUTCOME_WINNER,
                        targetSlot: $slot,
                    );
                }
            }
        }

        // --- grand final ----------------------------------------------------
        $matches[] = new PlannedMatch(
            ref: 'gf',
            round: $wbRounds + 1,
            position: 1,
            entrantIds: [1 => null, 2 => null],
            bracket: self::BRACKET_GRAND_FINAL,
        );

        // Slot 1 is always the winners-bracket representative. The reset rule
        // depends on that invariant: if slot 1 wins, they have never lost, and
        // the event is over.
        $connections[] = new PlannedConnection(
            sourceRef: sprintf('se-r%d-p1', $wbRounds),
            targetRef: 'gf',
            sourceOutcome: MatchConnection::OUTCOME_WINNER,
            targetSlot: 1,
        );

        if ($lbRounds >= 1) {
            $connections[] = new PlannedConnection(
                sourceRef: $this->lbRef($lbRounds, 1),
                targetRef: 'gf',
                sourceOutcome: MatchConnection::OUTCOME_WINNER,
                targetSlot: 2,
            );
        }

        if (($settings['grand_final_reset'] ?? true) === true) {
            // Created up front and cancelled if unneeded: the bracket must be
            // drawable in full before it is played, and a match that might not
            // happen is still part of the shape.
            $matches[] = new PlannedMatch(
                ref: 'gf-reset',
                round: $wbRounds + 2,
                position: 1,
                entrantIds: [1 => null, 2 => null],
                bracket: self::BRACKET_GRAND_FINAL,
            );

            $connections[] = new PlannedConnection('gf', 'gf-reset', MatchConnection::OUTCOME_WINNER, 1);
            $connections[] = new PlannedConnection('gf', 'gf-reset', MatchConnection::OUTCOME_LOSER, 2);
        }

        return new BracketPlan($matches, $connections, $winners->byeEntrantIds);
    }

    /**
     * How many matches a losers round holds.
     *
     * Round 1 halves the first-round losers. After that the rounds alternate:
     * a major round keeps the count, a minor round halves it — which is what
     * makes the losers bracket absorb arrivals without ever outgrowing itself.
     */
    public function losersRoundSize(int $bracketSize, int $lbRound): int
    {
        $firstRound = intdiv($bracketSize, 2);

        if ($lbRound === 1) {
            return intdiv($firstRound, 2);
        }

        return max(1, intdiv($firstRound, 2 ** (int) ceil($lbRound / 2)));
    }

    private function lbRef(int $round, int $position): string
    {
        return sprintf('lb-r%d-p%d', $round, $position);
    }
}

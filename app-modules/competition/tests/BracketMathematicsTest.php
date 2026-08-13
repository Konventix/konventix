<?php

declare(strict_types=1);

use Konventix\Competition\Brackets\Formats\DoubleEliminationGenerator;
use Konventix\Competition\Brackets\Formats\GroupStageGenerator;
use Konventix\Competition\Brackets\Formats\RoundRobinGenerator;
use Konventix\Competition\Brackets\Formats\SingleEliminationGenerator;
use Konventix\Competition\Brackets\Formats\SwissGenerator;
use Konventix\Competition\Brackets\Plan\PlannedMatch;

/**
 * The correctness properties of the bracket engine, asserted as *properties*
 * rather than fixtures.
 *
 * Every generator is a pure function of ids and settings, so none of this
 * touches a database — which is the point. Bracket correctness is the one
 * thing in this module worth proving exhaustively, and a proof that needs a
 * migration to run is a proof nobody runs.
 *
 * Helper names prefixed — Pest helpers are process-global (.ai/rules/tests.md).
 */
function bracketTestIds(int $count): array
{
    return array_map(fn (int $i): string => sprintf('E%03d', $i), range(1, $count));
}

/** @return list<array{string, string}> every unordered pair that played */
function bracketTestPairs(array $matches): array
{
    $pairs = [];

    foreach ($matches as $match) {
        /** @var PlannedMatch $match */
        $ids = array_values(array_filter($match->entrantIds));

        if (count($ids) === 2) {
            sort($ids);
            $pairs[] = $ids;
        }
    }

    return $pairs;
}

describe('single elimination — standard recursive seeding', function (): void {
    it('produces the documented seeding permutation', function (): void {
        // S(2n) = for each s in S(n): s, then 2n+1-s
        expect(SingleEliminationGenerator::seedOrder(2))->toBe([1, 2])
            ->and(SingleEliminationGenerator::seedOrder(4))->toBe([1, 4, 2, 3])
            ->and(SingleEliminationGenerator::seedOrder(8))->toBe([1, 8, 4, 5, 2, 7, 3, 6]);
    });

    it('pairs seeds summing to bracketSize + 1 in round one, at every size', function (): void {
        // Property 1: each first-round match is as lopsided as the field
        // allows — which is what a seeding is for.
        foreach ([2, 4, 8, 16, 32, 64] as $size) {
            $order = SingleEliminationGenerator::seedOrder($size);

            for ($i = 0; $i < $size; $i += 2) {
                expect($order[$i] + $order[$i + 1])->toBe($size + 1);
            }
        }
    });

    it('is a permutation of every seed exactly once', function (): void {
        foreach ([2, 4, 8, 16, 32] as $size) {
            $order = SingleEliminationGenerator::seedOrder($size);

            expect(count($order))->toBe($size)
                ->and(array_unique($order))->toHaveCount($size)
                ->and(min($order))->toBe(1)
                ->and(max($order))->toBe($size);
        }
    });

    it('keeps the top two seeds apart until the final', function (): void {
        // Property 2, the headline guarantee of standard seeding.
        $plan = (new SingleEliminationGenerator)->generate(bracketTestIds(16));

        // Seeds 1 and 2 are E001 and E002; they must not share a round-one
        // match, nor any match before the last round.
        $finalRound = $plan->rounds();

        foreach ($plan->matches as $match) {
            if ($match->round === $finalRound) {
                continue;
            }

            $ids = array_values(array_filter($match->entrantIds));
            expect($ids)->not->toBe(['E001', 'E002']);
        }
    });

    it('gives byes to the top seeds and nobody else', function (): void {
        // A consequence of the construction rather than a separate rule:
        // 12 entrants in a 16 bracket leaves 4 byes, for seeds 1-4.
        $plan = (new SingleEliminationGenerator)->generate(bracketTestIds(12));

        expect($plan->byeEntrantIds)->toHaveCount(4)
            ->and($plan->byeEntrantIds)->toEqualCanonicalizing(['E001', 'E002', 'E003', 'E004']);
    });

    it('produces exactly n-1 matches worth of decisions for a full bracket', function (): void {
        // A knockout of n needs n-1 eliminations, hence n-1 real matches.
        $plan = (new SingleEliminationGenerator)->generate(bracketTestIds(16));

        expect($plan->matchCount())->toBe(15)
            ->and($plan->rounds())->toBe(4);
    });

    it('wires every match to exactly one successor except the final', function (): void {
        $plan = (new SingleEliminationGenerator)->generate(bracketTestIds(8));

        // 7 matches, 6 edges: everyone advances somewhere but the final.
        expect($plan->matchCount())->toBe(7)
            ->and($plan->connections)->toHaveCount(6);

        $sources = array_map(fn ($c): string => $c->sourceRef, $plan->connections);
        expect(array_unique($sources))->toHaveCount(6);
    });

    it('fills both slots of each successor from the two matches below it', function (): void {
        $plan = (new SingleEliminationGenerator)->generate(bracketTestIds(8));

        $bySlot = [];

        foreach ($plan->connections as $connection) {
            $bySlot[$connection->targetRef][] = $connection->targetSlot;
        }

        foreach ($bySlot as $slots) {
            sort($slots);
            expect($slots)->toBe([1, 2]);
        }
    });
});

describe('round robin — circle method, a 1-factorization of K_n', function (): void {
    it('makes every pair meet exactly once, for every size', function (): void {
        // The completeness guarantee the construction proves.
        foreach ([2, 3, 4, 5, 6, 7, 8, 12, 15] as $n) {
            $plan = (new RoundRobinGenerator)->generate(bracketTestIds($n));
            $pairs = bracketTestPairs($plan->matches);

            $unique = array_unique(array_map(fn (array $p): string => implode('|', $p), $pairs));

            expect(count($pairs))->toBe($n * ($n - 1) / 2)
                ->and($unique)->toHaveCount($n * ($n - 1) / 2);
        }
    });

    it('uses the optimal number of rounds', function (): void {
        // n-1 for even n, n for odd (the virtual entrant makes it even).
        expect((new RoundRobinGenerator)->generate(bracketTestIds(8))->rounds())->toBe(7)
            ->and((new RoundRobinGenerator)->generate(bracketTestIds(7))->rounds())->toBe(7)
            ->and((new RoundRobinGenerator)->generate(bracketTestIds(6))->rounds())->toBe(5);
    });

    it('never pairs anyone with themselves', function (): void {
        foreach ([5, 8, 11] as $n) {
            foreach ((new RoundRobinGenerator)->generate(bracketTestIds($n))->matches as $match) {
                expect($match->entrantIds[1])->not->toBe($match->entrantIds[2]);
            }
        }
    });

    it('has each entrant play at most once per round', function (): void {
        // Each round is a perfect matching; anyone appearing twice would mean
        // the rotation is wrong.
        foreach ([6, 9, 12] as $n) {
            $plan = (new RoundRobinGenerator)->generate(bracketTestIds($n));

            for ($round = 1; $round <= $plan->rounds(); $round++) {
                $appearances = [];

                foreach ($plan->matchesInRound($round) as $match) {
                    foreach (array_filter($match->entrantIds) as $id) {
                        $appearances[] = $id;
                    }
                }

                expect(array_unique($appearances))->toHaveCount(count($appearances));
            }
        }
    });

    it('gives every entrant exactly one bye when the count is odd', function (): void {
        $plan = (new RoundRobinGenerator)->generate(bracketTestIds(7));

        expect($plan->byeEntrantIds)->toHaveCount(7)
            ->and(array_unique($plan->byeEntrantIds))->toHaveCount(7);
    });

    it('builds no progression graph, because standings decide it', function (): void {
        expect((new RoundRobinGenerator)->generate(bracketTestIds(6))->connections)->toBe([]);
    });
});

describe('swiss', function (): void {
    it('uses ceil(log2(n)) rounds', function (): void {
        expect(SwissGenerator::roundsFor(8))->toBe(3)
            ->and(SwissGenerator::roundsFor(16))->toBe(4)
            ->and(SwissGenerator::roundsFor(29))->toBe(5);
    });

    it('pairs round one in seed order', function (): void {
        $plan = (new SwissGenerator)->generate(bracketTestIds(8));

        expect($plan->matches[0]->entrantIds)->toBe([1 => 'E001', 2 => 'E002'])
            ->and($plan->matches[1]->entrantIds)->toBe([1 => 'E003', 2 => 'E004']);
    });

    it('byes the lowest seed when the field is odd', function (): void {
        $plan = (new SwissGenerator)->generate(bracketTestIds(5));

        expect($plan->matches)->toHaveCount(2)
            ->and($plan->byeEntrantIds)->toBe(['E005']);
    });

    it('avoids a rematch when one is avoidable', function (): void {
        $swiss = new SwissGenerator;
        $played = [$swiss->matchupKey('E001', 'E002')];

        $plan = $swiss->pairNextRound(['E001', 'E002', 'E003', 'E004'], $played, 2);

        foreach (bracketTestPairs($plan->matches) as $pair) {
            expect(implode('|', $pair))->not->toBe('E001|E002');
        }
    });

    it('falls back to a rematch when greedy first-fit strands the last pair', function (): void {
        // The documented limitation, asserted rather than hidden: greedy
        // first-fit pairs E001-E003 early, leaving E002 and E004 — who have
        // already played — as a forced rematch. A minimum-weight perfect
        // matching would have found E001-E004 / E002-E003 instead.
        $swiss = new SwissGenerator;
        $played = [
            $swiss->matchupKey('E001', 'E002'),
            $swiss->matchupKey('E002', 'E004'),
            $swiss->matchupKey('E003', 'E004'),
        ];

        $pairs = bracketTestPairs($swiss->pairNextRound(['E001', 'E002', 'E003', 'E004'], $played, 2)->matches);

        expect($pairs)->toHaveCount(2);
        // A rematch did occur — this is the heuristic's known failure mode.
        $rematched = array_filter(
            $pairs,
            fn (array $p): bool => in_array($swiss->matchupKey($p[0], $p[1]), $played, true),
        );
        expect($rematched)->not->toBeEmpty();
    });
});

describe('group stage — serpentine distribution', function (): void {
    it('deals seeds in the documented snake pattern', function (): void {
        $groups = (new GroupStageGenerator)->distribute(bracketTestIds(8), 2);

        expect($groups[0])->toBe(['E001', 'E004', 'E005', 'E008'])
            ->and($groups[1])->toBe(['E002', 'E003', 'E006', 'E007']);
    });

    it('balances group strength far better than sequential dealing', function (): void {
        // The property serpentine exists for: comparable total seed strength.
        $groups = (new GroupStageGenerator)->distribute(bracketTestIds(16), 4);

        $sums = array_map(
            fn (array $group): int => array_sum(array_map(
                fn (string $id): int => (int) substr($id, 1),
                $group,
            )),
            $groups,
        );

        expect(max($sums) - min($sums))->toBeLessThanOrEqual(count($groups) - 1);
    });

    it('keeps every entrant in exactly one group', function (): void {
        $groups = (new GroupStageGenerator)->distribute(bracketTestIds(29), 4);
        $all = array_merge(...$groups);

        expect($all)->toHaveCount(29)
            ->and(array_unique($all))->toHaveCount(29);
    });

    it('plays a full round robin inside each group and never across them', function (): void {
        $generator = new GroupStageGenerator;
        $plan = $generator->generate(bracketTestIds(8), ['group_count' => 2]);
        $groups = $generator->distribute(bracketTestIds(8), 2);

        // C(4,2) = 6 per group, twice.
        expect($plan->matchCount())->toBe(12);

        foreach ($plan->matches as $match) {
            $ids = array_values(array_filter($match->entrantIds));

            $inA = count(array_intersect($ids, $groups[0]));
            $inB = count(array_intersect($ids, $groups[1]));

            // Both sides in the same group — never a cross-group fixture.
            expect($inA === 2 || $inB === 2)->toBeTrue();
        }
    });
});

describe('double elimination', function (): void {
    it('sizes the losers bracket so everyone can lose twice', function (): void {
        // 8 entrants: WB 7 matches, LB 6, GF 1 (+ reset) = 2n-2 decisive
        // matches plus the possible reset.
        $plan = (new DoubleEliminationGenerator)->generate(bracketTestIds(8));

        $byBracket = [];

        foreach ($plan->matches as $match) {
            $byBracket[$match->bracket][] = $match;
        }

        expect($byBracket['winners'])->toHaveCount(7)
            ->and($byBracket['losers'])->toHaveCount(6)
            ->and($byBracket['grand_final'])->toHaveCount(2);
    });

    it('gives the losers bracket 2(k-1) rounds', function (): void {
        foreach ([[4, 2], [8, 4], [16, 6]] as [$entrants, $expected]) {
            $plan = (new DoubleEliminationGenerator)->generate(bracketTestIds($entrants));

            $lbRounds = max(array_map(
                fn ($m): int => $m->round,
                array_filter($plan->matches, fn ($m): bool => $m->bracket === 'losers'),
            ));

            expect($lbRounds)->toBe($expected);
        }
    });

    it('routes every winners-bracket loser somewhere', function (): void {
        // Nobody is eliminated by a single defeat until the losers bracket.
        $plan = (new DoubleEliminationGenerator)->generate(bracketTestIds(8));

        $wbRefs = array_map(
            fn ($m): string => $m->ref,
            array_filter($plan->matches, fn ($m): bool => $m->bracket === 'winners'),
        );

        $losersRouted = array_map(
            fn ($c): string => $c->sourceRef,
            array_filter($plan->connections, fn ($c): bool => $c->sourceOutcome === 'loser'),
        );

        // Every winners match except the final sends its loser down; the WB
        // final's loser goes to the losers final by the same mechanism.
        foreach ($wbRefs as $ref) {
            expect($losersRouted)->toContain($ref);
        }
    });

    it('crosses drop-ins over instead of feeding match i into match i', function (): void {
        // The deviation from the absorbed engine, asserted so it cannot be
        // silently undone: winners round 2 losers must not land in the
        // same-numbered losers match.
        $plan = (new DoubleEliminationGenerator)->generate(bracketTestIds(16));

        $round2Dropins = array_values(array_filter(
            $plan->connections,
            fn ($c): bool => $c->sourceOutcome === 'loser' && str_starts_with($c->sourceRef, 'se-r2-'),
        ));

        expect($round2Dropins)->not->toBeEmpty();

        $identity = array_filter($round2Dropins, function ($c): bool {
            preg_match('/p(\d+)$/', $c->sourceRef, $source);
            preg_match('/p(\d+)$/', $c->targetRef, $target);

            return $source[1] === $target[1];
        });

        // With a reversal, at most the middle element can map to itself.
        expect(count($identity))->toBeLessThan(count($round2Dropins));
    });

    it('puts the winners-bracket champion in slot 1 of the grand final', function (): void {
        // The reset rule depends on this invariant: if slot 1 wins, they have
        // never lost and the event is over.
        $plan = (new DoubleEliminationGenerator)->generate(bracketTestIds(8));

        $toGrandFinal = array_values(array_filter(
            $plan->connections,
            fn ($c): bool => $c->targetRef === 'gf',
        ));

        $fromWinners = array_values(array_filter(
            $toGrandFinal,
            fn ($c): bool => str_starts_with($c->sourceRef, 'se-'),
        ));

        expect($fromWinners)->toHaveCount(1)
            ->and($fromWinners[0]->targetSlot)->toBe(1);
    });

    it('creates the reset match up front and can be told not to', function (): void {
        $with = (new DoubleEliminationGenerator)->generate(bracketTestIds(8));
        $without = (new DoubleEliminationGenerator)->generate(bracketTestIds(8), ['grand_final_reset' => false]);

        $resetOf = fn ($plan): array => array_filter($plan->matches, fn ($m): bool => $m->ref === 'gf-reset');

        expect($resetOf($with))->toHaveCount(1)
            ->and($resetOf($without))->toBeEmpty();
    });

    it('inherits the winners bracket seeding wholesale', function (): void {
        // Double elimination restates none of single elimination's guarantees;
        // it reuses the generator, so they hold by construction.
        $single = (new SingleEliminationGenerator)->generate(bracketTestIds(8));
        $double = (new DoubleEliminationGenerator)->generate(bracketTestIds(8));

        $winnersRound1 = array_values(array_filter(
            $double->matches,
            fn ($m): bool => $m->bracket === 'winners' && $m->round === 1,
        ));

        foreach ($single->matchesInRound(1) as $index => $match) {
            expect($winnersRound1[$index]->entrantIds)->toBe($match->entrantIds);
        }
    });
});

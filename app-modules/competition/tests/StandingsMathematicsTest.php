<?php

declare(strict_types=1);

use Konventix\Competition\Brackets\Formats\DoubleEliminationGenerator;
use Konventix\Competition\Standings\Calculators\DoubleEliminationStandingsCalculator;
use Konventix\Competition\Standings\Calculators\GroupStandingsCalculator;
use Konventix\Competition\Standings\Calculators\PointsStandingsCalculator;
use Konventix\Competition\Standings\Calculators\SingleEliminationStandingsCalculator;
use Konventix\Competition\Standings\Calculators\SwissStandingsCalculator;
use Konventix\Competition\Standings\MatchOutcome;
use Konventix\Competition\Standings\MatchSide;
use Konventix\Competition\Standings\Placements;
use Konventix\Competition\Standings\StandingEntry;
use Konventix\Competition\Standings\StandingsEntrant;

/**
 * The ranking rules, proved without a database — the same split
 * `BracketMathematicsTest` uses, because the calculators are pure for exactly
 * this reason.
 *
 * Helper names prefixed: Pest helpers are process-global (.ai/rules/tests.md).
 */
function standingsTestField(int $count): array
{
    return array_map(
        fn (int $seed): StandingsEntrant => new StandingsEntrant(sprintf('E%03d', $seed), $seed),
        range(1, $count),
    );
}

function standingsTestId(int $seed): string
{
    return sprintf('E%03d', $seed);
}

/**
 * A finished head-to-head match. `$winner` is a seed number, or null for a draw.
 */
function standingsTestMatch(int $home, int $away, ?int $winner, int $round = 1, ?string $bracket = null, ?int $homeScore = null, ?int $awayScore = null): MatchOutcome
{
    return new MatchOutcome(
        round: $round,
        sides: [
            new MatchSide(standingsTestId($home), $homeScore),
            new MatchSide(standingsTestId($away), $awayScore),
        ],
        bracket: $bracket,
        winnerEntrantId: $winner === null ? null : standingsTestId($winner),
    );
}

/**
 * @return array<string, int> entrant id => placement
 */
function standingsTestPlacements(array $entries): array
{
    $placements = [];

    foreach ($entries as $entry) {
        $placements[$entry->entrantId] = $entry->placement;
    }

    return $placements;
}

describe('standard competition ranking', function (): void {
    it('shares a placement between tied rows and skips the ones consumed', function (): void {
        // The "1224" convention: two tied for 2nd means nobody is 3rd.
        $rows = [['v' => 9], ['v' => 5], ['v' => 5], ['v' => 1]];

        expect(Placements::assign($rows, fn (array $a, array $b): bool => $a['v'] === $b['v']))
            ->toBe([1, 2, 2, 4]);
    });

    it('gives everyone first place when nothing separates them', function (): void {
        $rows = [['v' => 1], ['v' => 1], ['v' => 1]];

        expect(Placements::assign($rows, fn (array $a, array $b): bool => $a['v'] === $b['v']))
            ->toBe([1, 1, 1]);
    });

    it('handles an empty field', function (): void {
        expect(Placements::assign([], fn (): bool => true))->toBe([]);
    });
});

describe('league standings', function (): void {
    it('awards the configured points for wins, draws and losses', function (): void {
        $entries = (new PointsStandingsCalculator)->calculate(
            standingsTestField(3),
            [
                standingsTestMatch(1, 2, 1),
                standingsTestMatch(1, 3, null),
                standingsTestMatch(2, 3, 2),
            ],
            ['points_win' => 3, 'points_draw' => 1, 'points_loss' => 0],
        );

        $points = [];

        foreach ($entries as $entry) {
            $points[$entry->entrantId] = $entry->points;
        }

        // Seed 1: a win and a draw = 4. Seed 2: a loss and a win = 3.
        // Seed 3: a draw and a loss = 1.
        expect($points)->toBe(['E001' => 4, 'E002' => 3, 'E003' => 1]);
    });

    it('honours a non-default points system', function (): void {
        // Two points for a win is a real ruleset, not a hypothetical.
        $entries = (new PointsStandingsCalculator)->calculate(
            standingsTestField(2),
            [standingsTestMatch(1, 2, 1)],
            ['points_win' => 2, 'points_draw' => 1, 'points_loss' => 0],
        );

        expect($entries[0]->points)->toBe(2);
    });

    it('breaks a tie on points by score difference', function (): void {
        // Both win once and lose once; seed 3 wins by more.
        $entries = (new PointsStandingsCalculator)->calculate(
            standingsTestField(3),
            [
                standingsTestMatch(1, 2, 1, homeScore: 1, awayScore: 0),
                standingsTestMatch(2, 3, 2, homeScore: 2, awayScore: 0),
                standingsTestMatch(3, 1, 3, homeScore: 9, awayScore: 0),
            ],
            [],
        );

        expect($entries[0]->entrantId)->toBe('E003')
            ->and($entries[0]->tiebreaker)->toBe(7.0);
    });

    it('shares a placement when points and difference are both level', function (): void {
        // A mutual 1-0: identical records, identical difference. Seeding must
        // not invent a winner.
        $entries = (new PointsStandingsCalculator)->calculate(
            standingsTestField(4),
            [
                standingsTestMatch(1, 2, 1, homeScore: 1, awayScore: 0),
                standingsTestMatch(3, 4, 3, homeScore: 1, awayScore: 0),
            ],
            [],
        );

        $placements = standingsTestPlacements($entries);

        expect($placements['E001'])->toBe(1)
            ->and($placements['E003'])->toBe(1)
            ->and($placements['E002'])->toBe(3)
            ->and($placements['E004'])->toBe(3);
    });

    it('ranks the whole field, including entrants who have not played', function (): void {
        $entries = (new PointsStandingsCalculator)->calculate(
            standingsTestField(4),
            [standingsTestMatch(1, 2, 1)],
            [],
        );

        expect($entries)->toHaveCount(4);
    });

    it('ignores a match involving someone no longer in the field', function (): void {
        $entries = (new PointsStandingsCalculator)->calculate(
            standingsTestField(2),
            [standingsTestMatch(1, 9, 1)],
            [],
        );

        expect($entries[0]->points)->toBe(0)
            ->and($entries[0]->played())->toBe(0);
    });

    it('counts a draw for both sides', function (): void {
        $entries = (new PointsStandingsCalculator)->calculate(
            standingsTestField(2),
            [standingsTestMatch(1, 2, null)],
            [],
        );

        expect($entries[0]->draws)->toBe(1)
            ->and($entries[1]->draws)->toBe(1)
            ->and($entries[0]->wins)->toBe(0);
    });
});

describe('group standings', function (): void {
    it('produces a separate table per group, each with its own first place', function (): void {
        $entries = (new GroupStandingsCalculator)->calculate(
            standingsTestField(4),
            [
                standingsTestMatch(1, 2, 1, bracket: 'group-A'),
                standingsTestMatch(3, 4, 4, bracket: 'group-B'),
            ],
            [],
        );

        $firsts = array_values(array_filter(
            $entries,
            fn (StandingEntry $entry): bool => $entry->placement === 1,
        ));

        expect($firsts)->toHaveCount(2)
            ->and(array_map(fn (StandingEntry $e): ?string => $e->group, $firsts))
            ->toEqualCanonicalizing(['group-A', 'group-B']);
    });

    it('puts each entrant only in the group they played in', function (): void {
        $entries = (new GroupStandingsCalculator)->calculate(
            standingsTestField(4),
            [
                standingsTestMatch(1, 2, 1, bracket: 'group-A'),
                standingsTestMatch(3, 4, 4, bracket: 'group-B'),
            ],
            [],
        );

        $groups = [];

        foreach ($entries as $entry) {
            $groups[$entry->entrantId] = $entry->group;
        }

        expect($groups)->toBe([
            'E001' => 'group-A', 'E002' => 'group-A',
            'E004' => 'group-B', 'E003' => 'group-B',
        ]);
    });
});

describe('single elimination standings', function (): void {
    it('places by the round you were knocked out in', function (): void {
        // A four-bracket: 1v4, 2v3, then the final.
        $entries = (new SingleEliminationStandingsCalculator)->calculate(
            standingsTestField(4),
            [
                standingsTestMatch(1, 4, 1, round: 1),
                standingsTestMatch(2, 3, 2, round: 1),
                standingsTestMatch(1, 2, 1, round: 2),
            ],
            [],
        );

        $placements = standingsTestPlacements($entries);

        expect($placements['E001'])->toBe(1)
            ->and($placements['E002'])->toBe(2);
    });

    it('shares third place between the semifinal losers rather than inventing a fourth', function (): void {
        // The deviation from the absorbed engine: no match decided which of
        // them was worse, so neither is 4th.
        $entries = (new SingleEliminationStandingsCalculator)->calculate(
            standingsTestField(4),
            [
                standingsTestMatch(1, 4, 1, round: 1),
                standingsTestMatch(2, 3, 2, round: 1),
                standingsTestMatch(1, 2, 1, round: 2),
            ],
            [],
        );

        $placements = standingsTestPlacements($entries);

        expect($placements['E003'])->toBe(3)
            ->and($placements['E004'])->toBe(3)
            ->and(array_values($placements))->not->toContain(4);
    });

    it('orders a shared tier by seed for a deterministic table', function (): void {
        $entries = (new SingleEliminationStandingsCalculator)->calculate(
            standingsTestField(4),
            [
                standingsTestMatch(1, 4, 1, round: 1),
                standingsTestMatch(2, 3, 2, round: 1),
                standingsTestMatch(1, 2, 1, round: 2),
            ],
            [],
        );

        // Both are 3rd; the better seed is listed first.
        expect($entries[2]->entrantId)->toBe('E003')
            ->and($entries[3]->entrantId)->toBe('E004');
    });

    it('gives eight entrants tiers of 1, 1, 2 and 4', function (): void {
        // The tiers are powers of two because the bracket is a binary tree.
        $outcomes = [
            standingsTestMatch(1, 8, 1, round: 1),
            standingsTestMatch(4, 5, 4, round: 1),
            standingsTestMatch(2, 7, 2, round: 1),
            standingsTestMatch(3, 6, 3, round: 1),
            standingsTestMatch(1, 4, 1, round: 2),
            standingsTestMatch(2, 3, 2, round: 2),
            standingsTestMatch(1, 2, 1, round: 3),
        ];

        $entries = (new SingleEliminationStandingsCalculator)->calculate(standingsTestField(8), $outcomes, []);

        $tiers = array_count_values(array_map(fn (StandingEntry $e): int => $e->placement, $entries));

        expect($tiers)->toBe([1 => 1, 2 => 1, 3 => 2, 5 => 4]);
    });

    it('returns nothing until the final is decided', function (): void {
        // A half-played bracket has no final placements to report.
        $entries = (new SingleEliminationStandingsCalculator)->calculate(
            standingsTestField(4),
            [standingsTestMatch(1, 4, 1, round: 1)],
            ['final_round' => 2],
        );

        expect($entries)->toBe([]);
    });
});

describe('double elimination standings', function (): void {
    $winners = DoubleEliminationGenerator::BRACKET_WINNERS;
    $losers = DoubleEliminationGenerator::BRACKET_LOSERS;
    $grandFinal = DoubleEliminationGenerator::BRACKET_GRAND_FINAL;

    it('ranks nobody on a winners-bracket loss alone', function () use ($winners, $losers, $grandFinal): void {
        // Losing in the winners bracket is not elimination — that is the
        // format. Seed 2 loses a winners match and still finishes 1st.
        $entries = (new DoubleEliminationStandingsCalculator)->calculate(
            standingsTestField(4),
            [
                standingsTestMatch(1, 2, 1, round: 2, bracket: $winners),
                standingsTestMatch(2, 3, 2, round: 2, bracket: $losers),
                standingsTestMatch(1, 2, 2, round: 3, bracket: $grandFinal),
            ],
            [],
        );

        expect(standingsTestPlacements($entries)['E002'])->toBe(1);
    });

    it('prefers the reset over the grand final when one was played', function () use ($grandFinal): void {
        $entries = (new DoubleEliminationStandingsCalculator)->calculate(
            standingsTestField(2),
            [
                standingsTestMatch(1, 2, 2, round: 3, bracket: $grandFinal),
                standingsTestMatch(1, 2, 1, round: 4, bracket: $grandFinal),
            ],
            [],
        );

        // The reset is the later round and it is what decided the event.
        expect(standingsTestPlacements($entries)['E001'])->toBe(1);
    });

    it('ranks a deeper losers round above a shallower one', function () use ($losers, $grandFinal): void {
        $entries = (new DoubleEliminationStandingsCalculator)->calculate(
            standingsTestField(4),
            [
                standingsTestMatch(3, 4, 3, round: 1, bracket: $losers),
                standingsTestMatch(2, 3, 2, round: 2, bracket: $losers),
                standingsTestMatch(1, 2, 1, round: 3, bracket: $grandFinal),
            ],
            [],
        );

        $placements = standingsTestPlacements($entries);

        // Out in LB round 2 beats out in LB round 1.
        expect($placements['E003'])->toBe(3)
            ->and($placements['E004'])->toBe(4);
    });

    it('returns nothing until the grand final is decided', function () use ($losers): void {
        $entries = (new DoubleEliminationStandingsCalculator)->calculate(
            standingsTestField(4),
            [standingsTestMatch(3, 4, 3, round: 1, bracket: $losers)],
            [],
        );

        expect($entries)->toBe([]);
    });
});

describe('swiss standings', function (): void {
    it('ranks on wins before anything else', function (): void {
        $entries = (new SwissStandingsCalculator)->calculate(
            standingsTestField(4),
            [
                standingsTestMatch(1, 2, 1, round: 1),
                standingsTestMatch(3, 4, 3, round: 1),
                standingsTestMatch(1, 3, 1, round: 2),
                standingsTestMatch(2, 4, 2, round: 2),
            ],
            [],
        );

        expect($entries[0]->entrantId)->toBe('E001')
            ->and($entries[0]->wins)->toBe(2)
            ->and($entries[3]->entrantId)->toBe('E004')
            ->and($entries[3]->wins)->toBe(0);
    });

    it('breaks a tie on Buchholz — the strength of who you had to play', function (): void {
        // Seeds 2 and 3 both finish on one win. Seed 2 beat seed 1 (who
        // finished on 2), seed 3 beat seed 4 (who finished on 0), so seed 2's
        // win was worth more.
        $entries = (new SwissStandingsCalculator)->calculate(
            standingsTestField(4),
            [
                standingsTestMatch(1, 4, 1, round: 1),
                standingsTestMatch(2, 3, 2, round: 1),
                standingsTestMatch(1, 2, 1, round: 2),
                standingsTestMatch(3, 4, 3, round: 2),
            ],
            [],
        );

        $placements = standingsTestPlacements($entries);

        expect($placements['E002'])->toBeLessThan($placements['E003']);
    });

    it('does not punish an entrant for taking a bye', function (): void {
        // The virtual opponent. Seed 1 and seed 2 both finish 2-0, but seed 1
        // took a bye in round 1. Counting real opponents only would give seed
        // 1 a smaller Buchholz and cost them the tiebreak — a placement lost
        // to the organiser's arithmetic rather than to anything played.
        $withBye = new MatchOutcome(
            round: 1,
            sides: [new MatchSide(standingsTestId(1))],
            winnerEntrantId: standingsTestId(1),
        );

        $entries = (new SwissStandingsCalculator)->calculate(
            standingsTestField(5),
            [
                $withBye,
                standingsTestMatch(2, 3, 2, round: 1),
                standingsTestMatch(4, 5, 4, round: 1),
                standingsTestMatch(1, 5, 1, round: 2),
                standingsTestMatch(2, 4, 2, round: 2),
            ],
            [],
        );

        $placements = standingsTestPlacements($entries);

        // Both finish 2-0. Counting real opponents only, seed 1's Buchholz is
        // 0 against seed 2's 1, and the bye costs them the tiebreak. The
        // virtual opponent makes it 2 against 1 instead.
        expect($placements['E001'])->toBe(1)
            ->and($placements['E002'])->toBe(2);
    });

    it('scores a draw as half a point', function (): void {
        $entries = (new SwissStandingsCalculator)->calculate(
            standingsTestField(2),
            [standingsTestMatch(1, 2, null, round: 1)],
            [],
        );

        expect($entries[0]->draws)->toBe(1)
            ->and($entries[0]->placement)->toBe(1)
            ->and($entries[1]->placement)->toBe(1);
    });

    it('collects the matchups already played, without duplicates', function (): void {
        $played = SwissStandingsCalculator::playedMatchups([
            standingsTestMatch(1, 2, 1, round: 1),
            standingsTestMatch(2, 1, 2, round: 2),
        ]);

        // Order-independent: the same pair is the same matchup.
        expect($played)->toBe(['E001|E002']);
    });

    it('ignores a bye when collecting matchups', function (): void {
        $played = SwissStandingsCalculator::playedMatchups([
            new MatchOutcome(round: 1, sides: [new MatchSide(standingsTestId(1))]),
        ]);

        expect($played)->toBe([]);
    });
});

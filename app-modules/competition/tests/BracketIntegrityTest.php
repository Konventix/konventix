<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Competition\Actions\ConfirmMatchResult;
use Konventix\Competition\Actions\CorrectMatchResult;
use Konventix\Competition\Actions\DisqualifyEntrant;
use Konventix\Competition\Actions\RecordResultAtDesk;
use Konventix\Competition\Actions\ReportMatchResult;
use Konventix\Competition\Actions\ResolveDispute;
use Konventix\Competition\Data\MatchReportData;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Exceptions\ResultSubmissionException;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchResultReport;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Models\Standing;

/**
 * Bracket and result correctness: the defects the Competition final review
 * found at the seams between tasks, each of which corrupted a live bracket
 * without an error anywhere (final review part 1, I1–I5; part 4, I1).
 *
 * Every expected value is derived by hand in the comment beside it. The
 * bracket shapes used throughout:
 *
 * - **Two entrants, single elimination**: one match, seed 1 in slot 1 and
 *   seed 2 in slot 2, no edges.
 * - **Four entrants, single elimination**: S(4) = [1, 4, 2, 3], so round 1
 *   match 1 is seed 1 v seed 4 and match 2 is seed 2 v seed 3; the final
 *   (round 2, match 1) takes match 1's winner in slot 1 and match 2's winner
 *   in slot 2 (`SingleEliminationGenerator`, odd position → slot 1). The
 *   tests still read the sides out with `competitionSidesOf()` rather than
 *   trusting the seeding, which is `BracketMathematicsTest`'s subject.
 *
 * Helper names prefixed `integrityTest` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
function integrityTestEntrant(string $entrantId): Entrant
{
    /** @var Entrant $entrant */
    $entrant = Entrant::query()->findOrFail($entrantId);

    return $entrant;
}

/**
 * A claim written straight to the table, the way one filed before a guard
 * existed (or before a correction moved the sides) sits there: the report
 * row and the match's `reported_at`, nothing else.
 *
 * @param  array<int, int|null>  $scores  position => score
 */
function integrityTestStoredClaim(CompetitionMatch $match, string $entrantId, array $scores, ?string $userId = null): void
{
    $report = new MatchResultReport([
        'competition_match_id' => $match->getKey(),
        'entrant_id' => $entrantId,
        'reported_by_user_id' => $userId ?? (string) Str::ulid(),
        'scores' => $scores,
    ]);
    $report->forceFill(['brand_id' => $match->brand_id])->save();

    $match->forceFill(['reported_at' => now()])->save();
}

/**
 * One match of a double-elimination stage. `competitionMatchAt()` cannot
 * tell them apart: the winners' and the losers' bracket both have a round 1
 * position 1.
 */
function integrityTestMatchIn(Stage $stage, string $bracket, int $round, int $position): CompetitionMatch
{
    /** @var CompetitionMatch $match */
    $match = CompetitionMatch::query()
        ->where('stage_id', $stage->getKey())
        ->where('bracket', $bracket)
        ->where('round', $round)
        ->where('position', $position)
        ->firstOrFail();

    return $match;
}

/**
 * The match of a stage in which the two entrants meet.
 */
function integrityTestMatchBetween(Stage $stage, string $one, string $other): CompetitionMatch
{
    foreach (CompetitionMatch::query()->where('stage_id', $stage->getKey())->get() as $match) {
        $sides = competitionSidesOf($match);

        if (in_array($one, $sides, true) && in_array($other, $sides, true)) {
            return $match;
        }
    }

    throw new RuntimeException('The two entrants never meet in this stage.');
}

/**
 * How many open (pending, scheduled, live) matches still hold the entrant.
 */
function integrityTestOpenMatchesOf(string $entrantId): int
{
    return CompetitionMatch::query()
        ->whereIn('id', MatchSlot::query()->select('competition_match_id')->where('entrant_id', $entrantId))
        ->whereIn('state', [MatchState::Pending->value, MatchState::Scheduled->value, MatchState::Live->value])
        ->count();
}

/*
 * ---------------------------------------------------------------------------
 * I1 — every settle path checks the winner (final review, part 1)
 * ---------------------------------------------------------------------------
 */

describe('the winner on every settle path (part 1, I1)', function (): void {
    it('refuses a drawn claim in single elimination when it is filed, and files nothing', function (): void {
        $this->withBrand();

        [$competition, , $members] = competitionFieldWithRosters(2, ['result_submission_mode' => ResultSubmissionMode::Participants]);
        $match = competitionMatchAt(competitionGeneratedStage($competition), 1, 1);
        $sides = competitionSidesOf($match);

        // 1:1 has two leaders, so `winnerFromScores()` is null: a draw. Two
        // empty scores have no leader at all: also a draw. Neither can be
        // sent on through an elimination bracket.
        foreach ([[1 => 1, 2 => 1], [1 => null, 2 => null]] as $claim) {
            expect(fn () => app(ReportMatchResult::class)->handle(
                $match->fresh(),
                integrityTestEntrant($sides[1]),
                new MatchReportData(scores: $claim),
                $members[$sides[1]],
            ))->toThrow(ResultSubmissionException::class, 'cannot end in a draw');
        }

        expect(MatchResultReport::query()->where('competition_match_id', $match->getKey())->count())->toBe(0)
            ->and($match->fresh()->reported_at)->toBeNull();
    });

    it('refuses to settle a stored tie on a participant confirm, and the waiting final stays open', function (): void {
        $this->withBrand();

        [$competition, , $members] = competitionFieldWithRosters(4, ['result_submission_mode' => ResultSubmissionMode::Participants]);
        $stage = competitionGeneratedStage($competition);
        $first = competitionMatchAt($stage, 1, 1);
        $sides = competitionSidesOf($first);

        // A 1:1 claim that got in before the filing guard existed. Before the
        // fix, confirming it finished match 1 with winner null; the final's
        // slot 1 stayed empty behind a *finished* feeder, which
        // `guardEverySideHasArrived` reads as a bye — match 2's winner would
        // then have walked over nobody.
        integrityTestStoredClaim($first, $sides[1], [1 => 1, 2 => 1], $members[$sides[1]]);

        expect(fn () => app(ConfirmMatchResult::class)->handle(
            $first->fresh(),
            integrityTestEntrant($sides[2]),
            $members[$sides[2]],
        ))->toThrow(ResultSubmissionException::class, 'cannot end in a draw');

        $final = competitionMatchAt($stage, 2, 1);

        expect($first->fresh()->state)->toBe(MatchState::Pending)
            ->and($first->fresh()->winner_entrant_id)->toBeNull()
            ->and(competitionSidesOf($final))->toBe([]);
    });

    it('still records an agreed draw where the discipline and the format both have draws', function (): void {
        // The control: the guard refuses a draw where the bracket cannot
        // carry one, not every draw. Round robin allows draws by default
        // (`RoundRobinGenerator::defaults()`), the discipline says so too.
        $this->withBrand();

        $discipline = Discipline::factory()->create(['allow_draws' => true]);

        [$competition, , $members] = competitionFieldWithRosters(2, [
            'discipline_id' => $discipline->getKey(),
            'result_submission_mode' => ResultSubmissionMode::Participants,
        ]);
        $match = competitionMatchAt(competitionGeneratedStage($competition, 'round_robin'), 1, 1);
        $sides = competitionSidesOf($match);

        foreach ([1, 2] as $position) {
            app(ReportMatchResult::class)->handle(
                $match->fresh(),
                integrityTestEntrant($sides[$position]),
                new MatchReportData(scores: [1 => 1, 2 => 1]),
                $members[$sides[$position]],
            );
        }

        expect($match->fresh()->state)->toBe(MatchState::Finished)
            ->and($match->fresh()->winner_entrant_id)->toBeNull();
    });

    it('refuses a desk correction to a stranger or to nobody, and leaves the final as it was', function (): void {
        $this->withBrand();

        [$competition] = competitionFieldWithRosters(4);
        $stage = competitionGeneratedStage($competition);
        $first = competitionMatchAt($stage, 1, 1);
        $second = competitionMatchAt($stage, 1, 2);
        [$a, $b] = array_values(competitionSidesOf($first));
        [$c] = array_values(competitionSidesOf($second));

        $desk = (string) Str::ulid();

        // A beats B 2:0; A goes to the final's slot 1.
        app(RecordResultAtDesk::class)->handle($first, [1 => 2, 2 => 0], $a, $desk);

        $final = competitionMatchAt($stage, 2, 1);
        expect(competitionSidesOf($final))->toBe([1 => $a]);

        // C plays in match 2, not in match 1: naming C the winner of match 1
        // would push C into the final's slot 1 as well.
        expect(fn () => app(CorrectMatchResult::class)->handle($first->fresh(), [$a => 0, $b => 2], $c, $desk, 'Vertauscht.'))
            ->toThrow(ResultSubmissionException::class, 'is not a side');

        // Nobody: an elimination match cannot end without a winner. Before the
        // fix, `reroute()` emptied the final's slot 1, which then read as a
        // bye.
        expect(fn () => app(CorrectMatchResult::class)->handle($first->fresh(), [$a => 1, $b => 1], null, $desk, 'Unentschieden.'))
            ->toThrow(ResultSubmissionException::class, 'cannot end in a draw');

        expect($first->fresh()->winner_entrant_id)->toBe($a)
            ->and(competitionSidesOf($final->fresh()))->toBe([1 => $a]);
    });

    it('refuses a dispute resolution that names somebody who is not a side', function (): void {
        $this->withBrand();

        [$competition, , $members] = competitionFieldWithRosters(4, ['result_submission_mode' => ResultSubmissionMode::Participants]);
        $stage = competitionGeneratedStage($competition);
        $first = competitionMatchAt($stage, 1, 1);
        $sides = competitionSidesOf($first);
        [$c] = array_values(competitionSidesOf(competitionMatchAt($stage, 1, 2)));

        app(ReportMatchResult::class)->handle($first, integrityTestEntrant($sides[1]), new MatchReportData(scores: [1 => 16, 2 => 9]), $members[$sides[1]]);
        app(ReportMatchResult::class)->handle($first->fresh(), integrityTestEntrant($sides[2]), new MatchReportData(scores: [1 => 9, 2 => 16]), $members[$sides[2]]);

        expect($first->fresh()->isDisputed())->toBeTrue();

        expect(fn () => app(ResolveDispute::class)->handle($first->fresh(), [1 => 16, 2 => 9], $c, (string) Str::ulid(), 'C gewinnt.'))
            ->toThrow(ResultSubmissionException::class, 'is not a side');

        // Nothing stamped: the dispute is still the desk's to decide.
        expect($first->fresh()->isDisputed())->toBeTrue()
            ->and($first->fresh()->state)->toBe(MatchState::Pending);
    });
});

/*
 * ---------------------------------------------------------------------------
 * I2 — a disqualification never dead-ends the bracket (final review, part 1)
 * ---------------------------------------------------------------------------
 */

describe('disqualification (part 1, I2)', function (): void {
    it('empties the slot in front of an opponent still to come, who then takes the match as a bye', function (): void {
        $this->withBrand();

        [$competition] = competitionFieldWithRosters(4);
        $stage = competitionGeneratedStage($competition);
        $first = competitionMatchAt($stage, 1, 1);
        $second = competitionMatchAt($stage, 1, 2);
        [$a] = array_values(competitionSidesOf($first));
        [$c] = array_values(competitionSidesOf($second));

        $desk = (string) Str::ulid();

        // A wins match 1 and waits in the final's slot 1; match 2 (C v D) is
        // still being played.
        app(RecordResultAtDesk::class)->handle($first, [1 => 2, 2 => 0], $a, $desk);

        $forfeited = app(DisqualifyEntrant::class)->handle(integrityTestEntrant($a));

        $final = competitionMatchAt($stage, 2, 1);

        // The final's slot 2 is fed by match 2, which is pending: the
        // opponent is still to come, so nothing is forfeited — A's slot is
        // emptied and the final stays open. Before the fix the final was
        // finished with no winner here, and C, arriving later, was put into
        // a match that was already over and never advanced.
        expect($forfeited)->toBe([])
            ->and($final->state)->toBe(MatchState::Pending)
            ->and($final->winner_entrant_id)->toBeNull()
            ->and(competitionSidesOf($final))->toBe([]);

        // C beats D and arrives in slot 2. Slot 1 is empty behind a finished
        // feeder (match 1): a bye, which the desk settles for C.
        app(RecordResultAtDesk::class)->handle($second->fresh(), [1 => 2, 2 => 0], $c, $desk);

        expect(competitionSidesOf($final->fresh()))->toBe([2 => $c]);

        app(RecordResultAtDesk::class)->handle($final->fresh(), [2 => 1], $c, $desk);

        expect($final->fresh()->state)->toBe(MatchState::Finished)
            ->and($final->fresh()->winner_entrant_id)->toBe($c);
    });

    it('never leaves a disqualified team in the losers\' bracket', function (): void {
        $this->withBrand();

        [$competition] = competitionFieldWithRosters(4);
        $stage = competitionGeneratedStage($competition, 'double_elimination');

        // Four entrants, bracket size 4, two winners' rounds
        // (`DoubleEliminationGenerator`):
        //   WB r1 p1: seed 1 v seed 4        WB r1 p2: seed 2 v seed 3
        //   LB r1 p1: slot 1 <- loser of WB r1 p1, slot 2 <- loser of WB r1 p2
        //   LB r2 p1: slot 1 <- winner of LB r1 p1, slot 2 <- loser of WB r2 p1
        $wb1 = integrityTestMatchIn($stage, 'winners', 1, 1);
        $wb2 = integrityTestMatchIn($stage, 'winners', 1, 2);
        $lb1 = integrityTestMatchIn($stage, 'losers', 1, 1);
        $lb2 = integrityTestMatchIn($stage, 'losers', 2, 1);
        [$a, $d] = array_values(competitionSidesOf($wb1));
        [$b, $c] = array_values(competitionSidesOf($wb2));

        $forfeited = app(DisqualifyEntrant::class)->handle(integrityTestEntrant($a));

        // Pass 1: WB r1 p1, D present — forfeited 1:0 to D. The forfeit makes
        // A its loser, and the loser edge drops A into LB r1 p1 slot 1.
        // Pass 2: LB r1 p1 holds A, and its slot 2 waits on WB r1 p2, which
        // is pending — A's slot is emptied. Pass 3: nothing holds A.
        // Before the fix the list was taken once, before the first forfeit,
        // so A stayed in the losers' bracket.
        expect($forfeited)->toBe([$wb1->id])
            ->and($wb1->fresh()->winner_entrant_id)->toBe($d)
            ->and(competitionSidesOf($lb1->fresh()))->toBe([])
            ->and(integrityTestOpenMatchesOf($a))->toBe(0);

        // B beats C: C drops into LB r1 p1 slot 2 and meets nobody — slot 1
        // sits behind WB r1 p1, finished — so the desk gives C the bye and C
        // moves on to LB r2 p1 slot 1.
        $desk = (string) Str::ulid();

        app(RecordResultAtDesk::class)->handle($wb2->fresh(), [1 => 2, 2 => 0], $b, $desk);

        expect(competitionSidesOf($lb1->fresh()))->toBe([2 => $c]);

        app(RecordResultAtDesk::class)->handle($lb1->fresh(), [2 => 1], $c, $desk);

        expect(competitionSidesOf($lb2->fresh()))->toBe([1 => $c]);
    });

    it('recomputes the standings, and the disqualified entrant leaves them', function (): void {
        $this->withBrand();

        [$competition] = competitionFieldWithRosters(3);
        $stage = competitionGeneratedStage($competition, 'round_robin');

        // Three entrants play three matches (the circle method adds a virtual
        // fourth, whose pairing each round is a bye).
        $field = Entrant::query()->where('competition_id', $competition->getKey())->orderBy('seed')->pluck('id')->all();
        [$a, $b, $c] = array_map(strval(...), $field);

        $ab = integrityTestMatchBetween($stage, $a, $b);
        $abSides = competitionSidesOf($ab);
        $aPosition = (int) array_search($a, $abSides, true);

        // A beats B at the desk; the settle recomputes: B is in the table.
        app(RecordResultAtDesk::class)->handle($ab, [$aPosition => 2, 3 - $aPosition => 0], $a, (string) Str::ulid());

        expect(Standing::query()->where('stage_id', $stage->getKey())->pluck('entrant_id')->all())->toContain($b);

        app(DisqualifyEntrant::class)->handle(integrityTestEntrant($b));

        // B v C is forfeited 1:0 to C. The recompute ranks the field, which no
        // longer holds B, and `PointsStandingsCalculator` skips every match
        // with a side outside the field — so A's win over B and C's forfeit
        // win over B both drop out. A and C have no counted match left:
        // 0 points and 0 difference each, which is a tie for 1st.
        // Before the fix nothing recomputed, and B stayed at its old place.
        $table = Standing::query()->where('stage_id', $stage->getKey())->get()->keyBy('entrant_id');

        expect(integrityTestEntrant($b)->state)->toBe(EntrantState::Disqualified)
            ->and($table->keys()->sort()->values()->all())->toBe(collect([$a, $c])->sort()->values()->all())
            ->and($table[$a]->placement)->toBe(1)
            ->and($table[$c]->placement)->toBe(1)
            ->and($table[$a]->points)->toBe(0)
            ->and($table[$c]->points)->toBe(0);
    });
});

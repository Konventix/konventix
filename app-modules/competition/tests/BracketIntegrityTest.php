<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Competition\Actions\CalculateStandings;
use Konventix\Competition\Actions\CheckInEntrant;
use Konventix\Competition\Actions\ConfirmMatchResult;
use Konventix\Competition\Actions\CorrectMatchResult;
use Konventix\Competition\Actions\DisputeMatchResult;
use Konventix\Competition\Actions\DisqualifyEntrant;
use Konventix\Competition\Actions\RecordMatchResult;
use Konventix\Competition\Actions\RecordResultAtDesk;
use Konventix\Competition\Actions\RegenerateBracket;
use Konventix\Competition\Actions\ReopenSignup;
use Konventix\Competition\Actions\ReportMatchResult;
use Konventix\Competition\Actions\ResetStage;
use Konventix\Competition\Actions\ResolveDispute;
use Konventix\Competition\Actions\ScheduleMatch;
use Konventix\Competition\Actions\StartCompetition;
use Konventix\Competition\Actions\SubmitScoreEntry;
use Konventix\Competition\Actions\WithdrawEntrant;
use Konventix\Competition\Data\LeaderboardSettings;
use Konventix\Competition\Data\MatchReportData;
use Konventix\Competition\Data\ScoreEntryData;
use Konventix\Competition\Desk\TournamentDesk;
use Konventix\Competition\Enums\CheckInMode;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\LeaderboardAggregate;
use Konventix\Competition\Enums\LeaderboardDirection;
use Konventix\Competition\Enums\MatchHistoryKind;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Enums\WithdrawalReason;
use Konventix\Competition\Events\MatchDisputeResolved;
use Konventix\Competition\Events\MatchFinished;
use Konventix\Competition\Exceptions\IllegalCompetitionStateTransitionException;
use Konventix\Competition\Exceptions\IllegalMatchStateTransitionException;
use Konventix\Competition\Exceptions\ResultSubmissionException;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchHistoryEntry;
use Konventix\Competition\Models\MatchResultReport;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Models\Standing;
use Konventix\Competition\Standings\LeaderboardStandingsCalculator;
use Konventix\Competition\Standings\ScoredEntry;
use Konventix\Competition\Standings\StandingsEntrant;

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

/*
 * ---------------------------------------------------------------------------
 * I3 — a double submit is refused on the match as it is now (final review,
 * part 1)
 * ---------------------------------------------------------------------------
 *
 * The race is two requests that both loaded the match before either
 * committed. A test cannot run them concurrently, but it can do exactly what
 * the second request does: hold a copy loaded before the first write, and
 * hand that stale copy to the Action afterwards.
 */

describe('a second submit on a stale copy (part 1, I3)', function (): void {
    it('records a match once, and sends MatchFinished once, when the desk records twice', function (): void {
        $this->withBrand();

        [$competition] = competitionFieldWithRosters(2);
        $match = competitionMatchAt(competitionGeneratedStage($competition), 1, 1);
        [$a, $b] = array_values(competitionSidesOf($match));

        // Two desk tabs, both opened while the match was pending.
        $firstTab = $match->fresh();
        $secondTab = $match->fresh();

        Event::fake([MatchFinished::class]);

        app(RecordMatchResult::class)->handle($firstTab, [$a => 2, $b => 0], $a);

        // The second tab still says `pending`. Re-read under the lock it is
        // `finished`, and `pending|scheduled|live → finished` is the only
        // legal move, so it is refused. Before the fix it recorded again and
        // `finished_event_emitted_at`, null on the stale copy, sent a second
        // `MatchFinished`.
        expect($secondTab->state)->toBe(MatchState::Pending);

        expect(fn () => app(RecordMatchResult::class)->handle($secondTab, [$a => 0, $b => 2], $b))
            ->toThrow(IllegalMatchStateTransitionException::class);

        Event::assertDispatchedTimes(MatchFinished::class, 1);

        expect($match->fresh()->winner_entrant_id)->toBe($a)
            ->and(MatchHistoryEntry::query()
                ->where('competition_match_id', $match->getKey())
                ->where('kind', MatchHistoryKind::Recorded->value)
                ->count())->toBe(1);
    });

    it('refuses the second of two confirms and sends MatchFinished once', function (): void {
        $this->withBrand();

        [$competition, , $members] = competitionFieldWithRosters(2, ['result_submission_mode' => ResultSubmissionMode::Participants]);
        $match = competitionMatchAt(competitionGeneratedStage($competition), 1, 1);
        $sides = competitionSidesOf($match);

        app(ReportMatchResult::class)->handle($match, integrityTestEntrant($sides[1]), new MatchReportData(scores: [1 => 16, 2 => 9]), $members[$sides[1]]);

        // Two members of side 2 open the match page and both press
        // "confirm".
        $firstPage = $match->fresh();
        $secondPage = $match->fresh();

        Event::fake([MatchFinished::class]);

        app(ConfirmMatchResult::class)->handle($firstPage, integrityTestEntrant($sides[2]), $members[$sides[2]]);

        expect(fn () => app(ConfirmMatchResult::class)->handle($secondPage, integrityTestEntrant($sides[2]), $members[$sides[2]]))
            ->toThrow(ResultSubmissionException::class, 'already has a recorded result');

        Event::assertDispatchedTimes(MatchFinished::class, 1);

        // One confirmation and one record on the timeline, not two of each.
        expect($match->history()->get()->map(fn (MatchHistoryEntry $entry): string => $entry->kind->value)->all())
            ->toBe(['reported', 'confirmed', 'recorded']);
    });

    it('resolves a dispute once when the desk resolves it twice', function (): void {
        $this->withBrand();

        [$competition, , $members] = competitionFieldWithRosters(2, ['result_submission_mode' => ResultSubmissionMode::Participants]);
        $match = competitionMatchAt(competitionGeneratedStage($competition), 1, 1);
        $sides = competitionSidesOf($match);

        app(ReportMatchResult::class)->handle($match, integrityTestEntrant($sides[1]), new MatchReportData(scores: [1 => 16, 2 => 9]), $members[$sides[1]]);
        app(ReportMatchResult::class)->handle($match->fresh(), integrityTestEntrant($sides[2]), new MatchReportData(scores: [1 => 9, 2 => 16]), $members[$sides[2]]);

        $firstTab = $match->fresh();
        $secondTab = $match->fresh();

        Event::fake([MatchFinished::class, MatchDisputeResolved::class]);

        $desk = (string) Str::ulid();

        app(ResolveDispute::class)->handle($firstTab, [1 => 16, 2 => 9], $sides[1], $desk, 'Demo geprüft.');

        // The stale copy still has `dispute_resolved_at` null. Under the
        // lock it is set, so there is no dispute left to resolve. Before the
        // fix the second resolve ran the "not finished" branch on the stale
        // copy and settled the match again.
        expect(fn () => app(ResolveDispute::class)->handle($secondTab, [1 => 9, 2 => 16], $sides[2], $desk, 'Doch anders.'))
            ->toThrow(ResultSubmissionException::class, 'not disputed');

        Event::assertDispatchedTimes(MatchFinished::class, 1);
        Event::assertDispatchedTimes(MatchDisputeResolved::class, 1);

        expect($match->fresh()->winner_entrant_id)->toBe($sides[1]);
    });

    it('never schedules a match back out of finished', function (): void {
        $this->withBrand();

        [$competition] = competitionFieldWithRosters(2);
        $match = competitionMatchAt(competitionGeneratedStage($competition), 1, 1);
        [$a] = array_values(competitionSidesOf($match));

        // The desk's schedule form was opened while the match was pending;
        // another lane records it before the schedule is saved.
        $scheduleForm = $match->fresh();

        app(RecordResultAtDesk::class)->handle($match->fresh(), [1 => 2, 2 => 0], $a, (string) Str::ulid());

        expect(fn () => app(ScheduleMatch::class)->handle($scheduleForm, CarbonImmutable::parse('2026-11-21 15:00:00', 'UTC')))
            ->toThrow(IllegalMatchStateTransitionException::class);

        expect($match->fresh()->state)->toBe(MatchState::Finished)
            ->and($match->fresh()->scheduled_at)->toBeNull();
    });

    it('refuses to correct a match an earlier correction voided', function (): void {
        $this->withBrand();

        [$competition] = competitionFieldWithRosters(4);
        $stage = competitionGeneratedStage($competition);
        $first = competitionMatchAt($stage, 1, 1);
        $second = competitionMatchAt($stage, 1, 2);
        [$a, $b] = array_values(competitionSidesOf($first));
        [$c] = array_values(competitionSidesOf($second));

        $desk = (string) Str::ulid();

        // A beats B, C beats D, A beats C in the final.
        app(RecordResultAtDesk::class)->handle($first, [1 => 2, 2 => 0], $a, $desk);
        app(RecordResultAtDesk::class)->handle($second, [1 => 2, 2 => 0], $c, $desk);
        $final = competitionMatchAt($stage, 2, 1);
        app(RecordResultAtDesk::class)->handle($final, [1 => 2, 2 => 0], $a, $desk);

        // A second desk tab holds the finished final.
        $staleFinal = $final->fresh();

        // Match 1 was the other way round: B replaces A in the final's slot
        // 1, and the final, played between A and C, is voided back to
        // `pending` (§8.5).
        app(CorrectMatchResult::class)->handle($first->fresh(), [$a => 0, $b => 2], $b, $desk, 'Vertauscht eingetragen.');

        expect($final->fresh()->state)->toBe(MatchState::Pending);

        // The stale tab still says `finished`. Under the lock the final is
        // pending, and a pending match is not corrected — it is played.
        expect(fn () => app(CorrectMatchResult::class)->handle($staleFinal, [$a => 0, $c => 2], $c, $desk, 'C gewinnt.'))
            ->toThrow(IllegalMatchStateTransitionException::class);

        expect($final->fresh()->winner_entrant_id)->toBeNull()
            ->and(competitionSidesOf($final->fresh()))->toBe([1 => $b, 2 => $c]);
    });
});

/*
 * ---------------------------------------------------------------------------
 * I4 — the drawn bracket cannot drift from the field (final review, part 1)
 * ---------------------------------------------------------------------------
 */

describe('the drawn field and the field (part 1, I4)', function (): void {
    it('refuses to reopen signup over a drawn bracket, and reopens once the draw is reset', function (): void {
        $this->withBrand();

        [$competition] = competitionFieldWithRosters(4);
        $stage = competitionGeneratedStage($competition);

        // Back in `Published`, signup could add an entry the bracket never
        // draws. Before the fix this went through.
        expect(fn () => app(ReopenSignup::class)->handle($competition->refresh()))
            ->toThrow(IllegalCompetitionStateTransitionException::class, 'reset the draw first');

        expect($competition->refresh()->status)->toBe(CompetitionStatus::SignupClosed);

        app(ResetStage::class)->handle($stage->refresh());
        app(ReopenSignup::class)->handle($competition->refresh());

        expect($competition->refresh()->status)->toBe(CompetitionStatus::Published);
    });

    it('refuses to start after a withdrawal changed the drawn field, and starts once it is drawn again', function (): void {
        $this->withBrand();

        [$competition, [, , , $fourth]] = competitionFieldWithRosters(4);
        $stage = competitionGeneratedStage($competition);

        expect($stage->drawn_entrant_ids)->toHaveCount(4);

        app(WithdrawEntrant::class)->handle($fourth, WithdrawalReason::OrganizerWithdrew);

        // Drawn {1, 2, 3, 4}; the field is now {1, 2, 3}: one drawn entrant is
        // no longer in it, and it is not disqualified. Before the fix the
        // tournament started with the withdrawn team still in its slots.
        expect(fn () => app(StartCompetition::class)->handle($competition->refresh()))
            ->toThrow(IllegalCompetitionStateTransitionException::class, '0 in the field but not drawn, 1 drawn but no longer in the field');

        expect($competition->refresh()->status)->toBe(CompetitionStatus::SignupClosed);

        // Drawn again from {1, 2, 3}: the two sets agree.
        app(RegenerateBracket::class)->handle($stage->refresh());
        app(StartCompetition::class)->handle($competition->refresh());

        expect($competition->refresh()->status)->toBe(CompetitionStatus::Running);
    });

    it('refuses to start after a late check-in joined a field that checks in', function (): void {
        $this->withBrand();

        [$competition, $field] = competitionFieldWithRosters(4, [
            'check_in_mode' => CheckInMode::Desk,
            'requires_event_check_in' => false,
        ]);

        // Three teams are checked in when the desk draws; the fourth is not,
        // and a field that checks in draws the checked-in only (`SignupField`).
        foreach (array_slice($field, 0, 3) as $entrant) {
            app(CheckInEntrant::class)->handle($entrant);
        }

        $stage = competitionGeneratedStage($competition);

        expect($stage->drawn_entrant_ids)->toHaveCount(3);

        // The fourth arrives late and the desk checks it in: the field is
        // {1, 2, 3, 4} against a drawn {1, 2, 3}.
        app(CheckInEntrant::class)->handle($field[3]->refresh());

        expect(fn () => app(StartCompetition::class)->handle($competition->refresh()))
            ->toThrow(IllegalCompetitionStateTransitionException::class, '1 in the field but not drawn, 0 drawn but no longer in the field');
    });

    it('starts over a disqualification, whose forfeits already dealt with the bracket', function (): void {
        $this->withBrand();

        [$competition, [$first]] = competitionFieldWithRosters(4);
        competitionGeneratedStage($competition);

        // Disqualified before the start: its round-1 match is forfeited, a
        // recorded result, so the desk could not draw again even if it had
        // to. Drawn {1, 2, 3, 4}, field {2, 3, 4}, disqualified {1}: nothing
        // is missing from the field and nothing gone from it but the
        // disqualified entrant.
        app(DisqualifyEntrant::class)->handle($first);
        app(StartCompetition::class)->handle($competition->refresh());

        expect($competition->refresh()->status)->toBe(CompetitionStatus::Running);
    });
});

/*
 * ---------------------------------------------------------------------------
 * I5 — settling a disputed match closes the dispute (final review, part 1)
 * ---------------------------------------------------------------------------
 */

describe('a dispute ends when its match is settled (part 1, I5)', function (): void {
    it('closes the dispute when the disputing side confirms after all', function (): void {
        $this->withBrand();

        [$competition, , $members] = competitionFieldWithRosters(2, ['result_submission_mode' => ResultSubmissionMode::Participants]);
        $match = competitionMatchAt(competitionGeneratedStage($competition), 1, 1);
        $sides = competitionSidesOf($match);

        app(ReportMatchResult::class)->handle($match, integrityTestEntrant($sides[1]), new MatchReportData(scores: [1 => 16, 2 => 9]), $members[$sides[1]]);
        app(DisputeMatchResult::class)->handle($match->fresh(), integrityTestEntrant($sides[2]), 'Das stimmt so nicht.', $members[$sides[2]]);

        expect($match->fresh()->isDisputed())->toBeTrue();

        Event::fake([MatchDisputeResolved::class]);

        // Side 2 looks again and confirms side 1's 16:9 after all.
        app(ConfirmMatchResult::class)->handle($match->fresh(), integrityTestEntrant($sides[2]), $members[$sides[2]]);

        // Finished for side 1 (16 > 9), and the dispute is over. Before the
        // fix `dispute_resolved_at` stayed null: finished yet "disputed", on
        // the desk's panel and in the open-disputes gauge.
        $settled = $match->fresh();

        expect($settled->state)->toBe(MatchState::Finished)
            ->and($settled->winner_entrant_id)->toBe($sides[1])
            ->and($settled->isDisputed())->toBeFalse()
            ->and($settled->dispute_resolved_at)->not->toBeNull()
            ->and($settled->history()->get()->map(fn (MatchHistoryEntry $entry): string => $entry->kind->value)->all())
            ->toBe(['reported', 'disputed', 'confirmed', 'resolved', 'recorded']);

        Event::assertDispatchedTimes(MatchDisputeResolved::class, 1);
        Event::assertDispatched(
            MatchDisputeResolved::class,
            fn (MatchDisputeResolved $event): bool => $event->entrantId === $sides[1] && $event->userId === $members[$sides[2]],
        );
    });

    it('closes the dispute when a re-report now agrees with the other side', function (): void {
        $this->withBrand();

        [$competition, , $members] = competitionFieldWithRosters(2, ['result_submission_mode' => ResultSubmissionMode::Participants]);
        $match = competitionMatchAt(competitionGeneratedStage($competition), 1, 1);
        $sides = competitionSidesOf($match);

        app(ReportMatchResult::class)->handle($match, integrityTestEntrant($sides[1]), new MatchReportData(scores: [1 => 16, 2 => 9]), $members[$sides[1]]);
        app(ReportMatchResult::class)->handle($match->fresh(), integrityTestEntrant($sides[2]), new MatchReportData(scores: [1 => 9, 2 => 16]), $members[$sides[2]]);

        expect($match->fresh()->isDisputed())->toBeTrue();

        // Side 2 corrects its own claim to side 1's 16:9: agreement.
        app(ReportMatchResult::class)->handle($match->fresh(), integrityTestEntrant($sides[2]), new MatchReportData(scores: [1 => 16, 2 => 9]), $members[$sides[2]]);

        $settled = $match->fresh();

        expect($settled->state)->toBe(MatchState::Finished)
            ->and($settled->winner_entrant_id)->toBe($sides[1])
            ->and($settled->isDisputed())->toBeFalse()
            ->and($settled->history()->get()->map(fn (MatchHistoryEntry $entry): string => $entry->kind->value)->all())
            ->toBe(['reported', 'reported', 'disputed', 'reported', 'confirmed', 'resolved', 'recorded']);
    });

    it('closes the dispute when the desk corrects the contested result', function (): void {
        $this->withBrand();

        [$competition, , $members] = competitionFieldWithRosters(2, ['result_submission_mode' => ResultSubmissionMode::Participants]);
        $match = competitionMatchAt(competitionGeneratedStage($competition), 1, 1);
        $sides = competitionSidesOf($match);

        $desk = (string) Str::ulid();

        // The desk records side 1 the winner; side 2 contests the recorded
        // result (§8.4 lets a side dispute a result that stands).
        app(RecordResultAtDesk::class)->handle($match, [1 => 2, 2 => 0], $sides[1], $desk);
        app(DisputeMatchResult::class)->handle($match->fresh(), integrityTestEntrant($sides[2]), 'Wir haben 2:0 gewonnen.', $members[$sides[2]]);

        expect($match->fresh()->isDisputed())->toBeTrue();

        // The desk corrects it directly rather than through "resolve". A two-
        // entrant bracket has nothing downstream, so no note is owed; the
        // `resolved` row then carries the German default the sides read.
        app(CorrectMatchResult::class)->handle($match->fresh(), [$sides[1] => 0, $sides[2] => 2], $sides[2], $desk);

        $corrected = $match->fresh();

        /** @var MatchHistoryEntry $resolved */
        $resolved = $corrected->history()->get()->last(fn (MatchHistoryEntry $entry): bool => $entry->kind === MatchHistoryKind::Resolved);

        expect($corrected->winner_entrant_id)->toBe($sides[2])
            ->and($corrected->isDisputed())->toBeFalse()
            ->and($resolved->payload['note'] ?? null)->toBe('Die Turnierleitung hat das Ergebnis korrigiert.');
    });
});

/*
 * ---------------------------------------------------------------------------
 * I6 — a leaderboard shows no value for an entrant with none (final review,
 * part 1)
 * ---------------------------------------------------------------------------
 */

describe('the leaderboard of a field that did not all score (part 1, I6)', function (): void {
    it('gives an entrant with no verified entry no tiebreaker rather than a zero', function (): void {
        // Lower wins (a lap time). E1 drove 41.9; E2 drove nothing. E2 has
        // no aggregate, so its tiebreaker is null, and it ranks behind every
        // entrant who scored. Before the fix it was 0.0 — on a `lower` board
        // the best "time" in the field, printed "0 s".
        $ranked = (new LeaderboardStandingsCalculator)->calculate(
            [new StandingsEntrant('E1', 1), new StandingsEntrant('E2', 2)],
            [new ScoredEntry(entrantId: 'E1', value: 41.9, heat: null, verified: true)],
            new LeaderboardSettings(direction: LeaderboardDirection::Lower, aggregate: LeaderboardAggregate::Best, unit: 's', decimals: 3),
        );

        expect(array_map(static fn ($entry): array => [$entry->entrantId, $entry->placement, $entry->tiebreaker], $ranked))
            ->toBe([['E1', 1, 41.9], ['E2', 2, null]]);
    });

    it('stores the empty tiebreaker and leaves an undrawn no-show off a check-in board', function (): void {
        $this->withBrand();

        [$competition, [$first, $second, $noShow]] = competitionFieldWithRosters(3, [
            'check_in_mode' => CheckInMode::Desk,
            'requires_event_check_in' => false,
        ]);
        $stage = Stage::factory()->leaderboard(new LeaderboardSettings(
            direction: LeaderboardDirection::Lower,
            aggregate: LeaderboardAggregate::Best,
            unit: 's',
            decimals: 3,
        ))->create(['competition_id' => $competition->getKey()]);

        // The first two are checked in; the third never came.
        app(CheckInEntrant::class)->handle($first);
        app(CheckInEntrant::class)->handle($second);

        app(SubmitScoreEntry::class)->handle($stage, $first->refresh(), new ScoreEntryData(value: 41.9), (string) Str::ulid(), asOrganizer: true);

        app(CalculateStandings::class)->handle($stage->fresh());

        // The field is the checked-in pair (`SignupField` under check-in).
        // First: 41.9, placed 1st. Second: no entry, so no value, placed 2nd.
        // The registered no-show is not ranked at all; before the fix it
        // shared the last place with "0 s".
        $table = Standing::query()->where('stage_id', $stage->getKey())->get()->keyBy('entrant_id');

        expect($table->keys()->sort()->values()->all())->toBe(collect([$first->id, $second->id])->sort()->values()->all())
            ->and($table[$first->id]->placement)->toBe(1)
            ->and($table[$first->id]->tiebreaker)->toBe(41.9)
            ->and($table[$second->id]->placement)->toBe(2)
            ->and($table[$second->id]->tiebreaker)->toBeNull()
            ->and($table->has($noShow->id))->toBeFalse();
    });
});

/*
 * ---------------------------------------------------------------------------
 * Part 4, I1 — a correction takes the old pairing's claims with it
 * ---------------------------------------------------------------------------
 */

/**
 * Four entrants, participant-reported: A beats B and C beats D at the desk,
 * so the final is A (slot 1) v C (slot 2). Returns the stage, the four
 * entrant ULIDs in that naming and the roster member of each.
 *
 * @return array{0: Stage, 1: array{a: string, b: string, c: string, d: string}, 2: array<string, string>}
 */
function integrityTestFinalReached(): array
{
    [$competition, , $members] = competitionFieldWithRosters(4, ['result_submission_mode' => ResultSubmissionMode::Participants]);
    $stage = competitionGeneratedStage($competition);
    $first = competitionMatchAt($stage, 1, 1);
    $second = competitionMatchAt($stage, 1, 2);
    [$a, $b] = array_values(competitionSidesOf($first));
    [$c, $d] = array_values(competitionSidesOf($second));

    $desk = (string) Str::ulid();
    app(RecordResultAtDesk::class)->handle($first, [1 => 2, 2 => 0], $a, $desk);
    app(RecordResultAtDesk::class)->handle($second, [1 => 2, 2 => 0], $c, $desk);

    return [$stage, ['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d], $members];
}

describe('claims on a pairing a correction changed (part 4, I1)', function (): void {
    it('drops the reported claim on the final when a correction replaces its reporter, so nobody can confirm it', function (): void {
        $this->withBrand();

        [$stage, $e, $members] = integrityTestFinalReached();
        $final = competitionMatchAt($stage, 2, 1);

        // A claims the final 16:12 (A in slot 1).
        app(ReportMatchResult::class)->handle($final, integrityTestEntrant($e['a']), new MatchReportData(scores: [1 => 16, 2 => 12]), $members[$e['a']]);

        // Match 1 was B's: B replaces A in the final's slot 1. The final was
        // never played, so nothing is voided — but A's claim was about A v C.
        app(CorrectMatchResult::class)->handle(competitionMatchAt($stage, 1, 1), [$e['a'] => 0, $e['b'] => 2], $e['b'], (string) Str::ulid());

        $final->refresh();

        expect(competitionSidesOf($final))->toBe([1 => $e['b'], 2 => $e['c']])
            ->and(MatchResultReport::query()->where('competition_match_id', $final->getKey())->count())->toBe(0)
            ->and($final->reported_at)->toBeNull();

        // Before the fix C could confirm A's 16:12, and slot 1 — now B —
        // was recorded the winner of a match B never played.
        expect(fn () => app(ConfirmMatchResult::class)->handle($final, integrityTestEntrant($e['c']), $members[$e['c']]))
            ->toThrow(ResultSubmissionException::class, 'nothing to confirm');

        expect($final->fresh()->state)->toBe(MatchState::Pending);
    });

    it('drops a dispute about the old pairing from the desk', function (): void {
        $this->withBrand();

        [$stage, $e, $members] = integrityTestFinalReached();
        $final = competitionMatchAt($stage, 2, 1);

        // A claims 16:12, C claims 12:16: disputed, on the desk's panel.
        app(ReportMatchResult::class)->handle($final, integrityTestEntrant($e['a']), new MatchReportData(scores: [1 => 16, 2 => 12]), $members[$e['a']]);
        app(ReportMatchResult::class)->handle($final->fresh(), integrityTestEntrant($e['c']), new MatchReportData(scores: [1 => 12, 2 => 16]), $members[$e['c']]);

        $competition = $stage->competition()->withoutGlobalScopes()->firstOrFail();

        expect(app(TournamentDesk::class)->for($competition)->disputes)->toHaveCount(1);

        app(CorrectMatchResult::class)->handle(competitionMatchAt($stage, 1, 1), [$e['a'] => 0, $e['b'] => 2], $e['b'], (string) Str::ulid());

        // A dispute between A and C about a match A no longer plays is
        // nobody's to decide.
        expect($final->fresh()->isDisputed())->toBeFalse()
            ->and($final->fresh()->disputed_at)->toBeNull()
            ->and(app(TournamentDesk::class)->for($competition->refresh())->disputes)->toBe([]);
    });

    it('never lets a side confirm a claim filed by an entrant no longer in the match', function (): void {
        // The belt to the cascade's braces: whatever put it there, a report
        // by an entrant outside the match is nobody's to confirm.
        $this->withBrand();

        [$stage, $e, $members] = integrityTestFinalReached();
        $final = competitionMatchAt($stage, 2, 1);

        // A claim by D, who lost in round 1 and is not in the final (A v C).
        integrityTestStoredClaim($final, $e['d'], [1 => 12, 2 => 16]);

        expect(fn () => app(ConfirmMatchResult::class)->handle($final->fresh(), integrityTestEntrant($e['c']), $members[$e['c']]))
            ->toThrow(ResultSubmissionException::class, 'nothing to confirm');

        expect($final->fresh()->state)->toBe(MatchState::Pending);
    });
});

/*
 * ---------------------------------------------------------------------------
 * Round 2, N1 — a match nobody can reach is closed, so no bracket dead-ends
 * ---------------------------------------------------------------------------
 */

/**
 * The desk records `$winnerId` the winner of `$match`, 2:0, or 2 alone on a
 * bye — the production path (`RecordResultAtDesk`), which advances the
 * bracket.
 */
function integrityTestWin(CompetitionMatch $match, string $winnerId): void
{
    $scores = [];

    foreach (competitionSidesOf($match->fresh()) as $position => $entrantId) {
        $scores[$position] = $entrantId === $winnerId ? 2 : 0;
    }

    app(RecordResultAtDesk::class)->handle($match->fresh(), $scores, $winnerId, (string) Str::ulid());
}

describe('matches nobody can reach (round 2, N1)', function (): void {
    it('closes the losers\' match a disqualification and a bye left empty, and plays double elimination of three to the grand final', function (): void {
        $this->withBrand();

        [$competition, [$a, $b, $c]] = competitionFieldWithRosters(3);
        $stage = competitionGeneratedStage($competition, 'double_elimination');

        // Three entrants, bracket size 4, S(4) = [1, 4, 2, 3]:
        //   WB r1 p1: A (seed 1) v bye        WB r1 p2: B (seed 2) v C (seed 3)
        //   WB r2 p1: slot 1 <- winner WB r1 p1, slot 2 <- winner WB r1 p2
        //   LB r1 p1: slot 1 <- loser WB r1 p1, slot 2 <- loser WB r1 p2
        //   LB r2 p1: slot 1 <- winner LB r1 p1, slot 2 <- loser WB r2 p1
        //   GF (round 3): slot 1 <- winner WB r2 p1, slot 2 <- winner LB r2 p1
        $wb1 = integrityTestMatchIn($stage, 'winners', 1, 1);
        $wb2 = integrityTestMatchIn($stage, 'winners', 1, 2);
        $wbFinal = integrityTestMatchIn($stage, 'winners', 2, 1);
        $lb1 = integrityTestMatchIn($stage, 'losers', 1, 1);
        $lb2 = integrityTestMatchIn($stage, 'losers', 2, 1);
        $grandFinal = integrityTestMatchIn($stage, 'grand_final', 3, 1);

        expect(competitionSidesOf($wb1))->toBe([1 => $a->id])
            ->and(competitionSidesOf($wb2))->toBe([1 => $b->id, 2 => $c->id]);

        // B is disqualified before the desk has settled A's bye. Pass 1: WB r1
        // p2 is forfeited to C; C goes to WB r2 p1 slot 2 and B, the loser, to
        // LB r1 p1 slot 2. Pass 2: LB r1 p1's slot 1 is fed by WB r1 p1, still
        // pending, so B is emptied out. LB r1 p1 now has no side.
        app(DisqualifyEntrant::class)->handle($b);

        expect(competitionSidesOf($lb1->fresh()))->toBe([])
            ->and($lb1->fresh()->state)->toBe(MatchState::Pending);

        // The desk settles A's bye. WB r1 p1 has no loser, so its loser edge
        // delivers nobody: LB r1 p1 has no side and both its feeders are
        // finished — it is closed with no winner. Before the fix it stayed
        // pending for ever, and LB r2 p1's slot 1 waited on it for ever.
        integrityTestWin($wb1, $a->id);

        expect($lb1->fresh()->state)->toBe(MatchState::Finished)
            ->and($lb1->fresh()->winner_entrant_id)->toBeNull()
            ->and(competitionSidesOf($wbFinal->fresh()))->toBe([1 => $a->id, 2 => $c->id]);

        // A beats C: A to the grand final's slot 1, C drops to LB r2 p1 slot
        // 2. Its slot 1 sits behind LB r1 p1, now finished: a bye for C.
        integrityTestWin($wbFinal, $a->id);

        expect(competitionSidesOf($lb2->fresh()))->toBe([2 => $c->id]);

        integrityTestWin($lb2, $c->id);

        expect(competitionSidesOf($grandFinal->fresh()))->toBe([1 => $a->id, 2 => $c->id]);
    });

    it('closes a single-elimination match two disqualifications on converging paths left empty, and plays to the final', function (): void {
        $this->withBrand();

        [$competition, [$s1, $s2, $s3, $s4, $s5]] = competitionFieldWithRosters(5);
        $stage = competitionGeneratedStage($competition);

        // Five entrants, bracket size 8, S(8) = [1, 8, 4, 5, 2, 7, 3, 6]:
        //   r1 p1: s1 v bye   r1 p2: s4 v s5   r1 p3: s2 v bye   r1 p4: s3 v bye
        //   r2 p1 <- r1 p1 (slot 1), r1 p2 (slot 2); r2 p2 <- r1 p3, r1 p4
        //   final (r3 p1) <- r2 p1 (slot 1), r2 p2 (slot 2)
        expect(competitionSidesOf(competitionMatchAt($stage, 1, 1)))->toBe([1 => $s1->id])
            ->and(competitionSidesOf(competitionMatchAt($stage, 1, 2)))->toBe([1 => $s4->id, 2 => $s5->id])
            ->and(competitionSidesOf(competitionMatchAt($stage, 1, 3)))->toBe([1 => $s2->id])
            ->and(competitionSidesOf(competitionMatchAt($stage, 1, 4)))->toBe([1 => $s3->id]);

        // s4 beats s5 and waits in r2 p1 slot 2.
        integrityTestWin(competitionMatchAt($stage, 1, 2), $s4->id);

        // s4 is disqualified: r2 p1's slot 1 is fed by r1 p1, s1's unsettled
        // bye, so s4 is emptied out — the awaited case. r2 p1 has no side.
        app(DisqualifyEntrant::class)->handle($s4->refresh());

        $semi = competitionMatchAt($stage, 2, 1);

        expect(competitionSidesOf($semi))->toBe([])
            ->and($semi->state)->toBe(MatchState::Pending);

        // s1 is disqualified before its bye is settled: nobody is coming to
        // r1 p1, so it finishes with no winner. Now r2 p1 has no side and
        // both feeders are finished: closed with no winner. Before the fix it
        // stayed pending, and the final's slot 1 waited on it for ever.
        app(DisqualifyEntrant::class)->handle($s1->refresh());

        expect($semi->fresh()->state)->toBe(MatchState::Finished)
            ->and($semi->fresh()->winner_entrant_id)->toBeNull();

        // The other half plays out: s2 and s3 take their byes, s2 beats s3
        // and reaches the final's slot 2. Slot 1 sits behind r2 p1, finished
        // with nobody: a bye, which the desk gives s2.
        integrityTestWin(competitionMatchAt($stage, 1, 3), $s2->id);
        integrityTestWin(competitionMatchAt($stage, 1, 4), $s3->id);
        integrityTestWin(competitionMatchAt($stage, 2, 2), $s2->id);

        $final = competitionMatchAt($stage, 3, 1);

        expect(competitionSidesOf($final))->toBe([2 => $s2->id]);

        integrityTestWin($final, $s2->id);

        expect($final->fresh()->state)->toBe(MatchState::Finished)
            ->and($final->fresh()->winner_entrant_id)->toBe($s2->id);
    });

    it('plays double elimination of five, whose two byes empty a losers\' match, to the grand final without any disqualification', function (): void {
        $this->withBrand();

        [$competition, [$s1, $s2, $s3, $s4, $s5]] = competitionFieldWithRosters(5);
        $stage = competitionGeneratedStage($competition, 'double_elimination');

        // Size 8, three winners' rounds, four losers' rounds
        // (`DoubleEliminationGenerator`; losersRoundSize(8, r) = 2, 2, 1, 1):
        //   WB r1: p1 s1 v bye, p2 s4 v s5, p3 s2 v bye, p4 s3 v bye
        //   WB r2 p1 <- r1 p1, r1 p2;  WB r2 p2 <- r1 p3, r1 p4;  WB r3 p1 <- r2 p1, r2 p2
        //   LB r1 p1 <- losers of WB r1 p1 (slot 1), p2 (slot 2)
        //   LB r1 p2 <- losers of WB r1 p3 (slot 1), p4 (slot 2)
        //   LB r2 p1 <- winner LB r1 p1 (slot 1), loser WB r2 p2 (slot 2, crossover)
        //   LB r2 p2 <- winner LB r1 p2 (slot 1), loser WB r2 p1 (slot 2, crossover)
        //   LB r3 p1 <- winners LB r2 p1 (slot 1), LB r2 p2 (slot 2)
        //   LB r4 p1 <- winner LB r3 p1 (slot 1), loser WB r3 p1 (slot 2)
        //   GF (round 4) <- winner WB r3 p1 (slot 1), winner LB r4 p1 (slot 2)
        $wb = fn (int $round, int $position): CompetitionMatch => integrityTestMatchIn($stage, 'winners', $round, $position);
        $lb = fn (int $round, int $position): CompetitionMatch => integrityTestMatchIn($stage, 'losers', $round, $position);

        expect(competitionSidesOf($wb(1, 1)))->toBe([1 => $s1->id])
            ->and(competitionSidesOf($wb(1, 2)))->toBe([1 => $s4->id, 2 => $s5->id])
            ->and(competitionSidesOf($wb(1, 3)))->toBe([1 => $s2->id])
            ->and(competitionSidesOf($wb(1, 4)))->toBe([1 => $s3->id]);

        // The three byes. WB r1 p3 and p4 have no loser, so LB r1 p2 gets
        // nobody from either: once both are settled it has no side behind
        // finished feeders and is closed. Before the fix it stayed pending
        // with no side, and LB r2 p2 waited on it for ever.
        integrityTestWin($wb(1, 1), $s1->id);
        integrityTestWin($wb(1, 3), $s2->id);

        expect($lb(1, 2)->state)->toBe(MatchState::Pending);

        integrityTestWin($wb(1, 4), $s3->id);

        expect($lb(1, 2)->state)->toBe(MatchState::Finished)
            ->and($lb(1, 2)->winner_entrant_id)->toBeNull()
            // LB r1 p1 got s1's bye's nobody, but WB r1 p2 is still to play:
            // it stays open.
            ->and($lb(1, 1)->state)->toBe(MatchState::Pending);

        // s4 beats s5: s5 drops to LB r1 p1 slot 2, a bye behind WB r1 p1.
        integrityTestWin($wb(1, 2), $s4->id);
        expect(competitionSidesOf($lb(1, 1)))->toBe([2 => $s5->id]);
        integrityTestWin($lb(1, 1), $s5->id);

        // WB r2 p1: s1 beats s4; s4 drops to LB r2 p2 slot 2, a bye behind
        // LB r1 p2 (closed).
        integrityTestWin($wb(2, 1), $s1->id);
        expect(competitionSidesOf($lb(2, 2)))->toBe([2 => $s4->id]);
        integrityTestWin($lb(2, 2), $s4->id);

        // WB r2 p2: s2 beats s3; s3 drops to LB r2 p1 slot 2 against s5.
        integrityTestWin($wb(2, 2), $s2->id);
        expect(competitionSidesOf($lb(2, 1)))->toBe([1 => $s5->id, 2 => $s3->id]);
        integrityTestWin($lb(2, 1), $s3->id);

        // LB r3 p1: s3 (slot 1) beats s4 (slot 2).
        expect(competitionSidesOf($lb(3, 1)))->toBe([1 => $s3->id, 2 => $s4->id]);
        integrityTestWin($lb(3, 1), $s3->id);

        // WB r3 p1: s1 beats s2; s2 drops to LB r4 p1 slot 2 against s3.
        integrityTestWin($wb(3, 1), $s1->id);
        expect(competitionSidesOf($lb(4, 1)))->toBe([1 => $s3->id, 2 => $s2->id]);
        integrityTestWin($lb(4, 1), $s2->id);

        expect(competitionSidesOf(integrityTestMatchIn($stage, 'grand_final', 4, 1)))->toBe([1 => $s1->id, 2 => $s2->id]);
    });
});

/*
 * ---------------------------------------------------------------------------
 * Round 2, N2 — a withdrawal after a result cannot strand the start
 * ---------------------------------------------------------------------------
 */

describe('the start after a pre-start result (round 2, N2)', function (): void {
    it('forfeits a captain\'s withdrawal once a disqualification stands, and starts', function (): void {
        $this->withBrand();

        [$competition, [$s1, $s2, $s3, $s4]] = competitionFieldWithRosters(4);
        $stage = competitionGeneratedStage($competition);

        // SE4: m1 = s1 v s4, m2 = s2 v s3, the final takes their winners.
        // s4 does not show and is disqualified: m1 is forfeited to s1, a
        // recorded result, so the desk can no longer draw again.
        app(DisqualifyEntrant::class)->handle($s4);

        // s3's captain withdraws, still allowed in `SignupClosed`. A result
        // stands, so the withdrawal forfeits m2 to s2. Before the fix s3 kept
        // its slot: drawn {1,2,3,4} − field {1,2} − disqualified {4} = {3},
        // start refused, redraw refused, a disqualification of the withdrawn
        // team refused — only Cancel was left.
        app(WithdrawEntrant::class)->handle($s3, WithdrawalReason::CaptainWithdrew);

        $second = competitionMatchAt($stage, 1, 2);

        expect($second->state)->toBe(MatchState::Finished)
            ->and($second->winner_entrant_id)->toBe($s2->id)
            ->and(competitionSidesOf(competitionMatchAt($stage, 2, 1)))->toBe([1 => $s1->id, 2 => $s2->id]);

        // Drawn {1,2,3,4}; field {1,2}; retired and in no open match {3,4}:
        // nothing is missing and nothing unaccounted for.
        app(StartCompetition::class)->handle($competition->refresh());

        expect($competition->refresh()->status)->toBe(CompetitionStatus::Running);
    });
});

describe('a forfeit of a disputed match (round 2, M-a)', function (): void {
    it('ends the dispute when a disqualification forfeits the match, and names nobody', function (): void {
        $this->withBrand();

        [$competition, , $members] = competitionFieldWithRosters(2, ['result_submission_mode' => ResultSubmissionMode::Participants]);
        $match = competitionMatchAt(competitionGeneratedStage($competition), 1, 1);
        $sides = competitionSidesOf($match);

        app(ReportMatchResult::class)->handle($match, integrityTestEntrant($sides[1]), new MatchReportData(scores: [1 => 16, 2 => 9]), $members[$sides[1]]);
        app(ReportMatchResult::class)->handle($match->fresh(), integrityTestEntrant($sides[2]), new MatchReportData(scores: [1 => 9, 2 => 16]), $members[$sides[2]]);

        expect($match->fresh()->isDisputed())->toBeTrue();

        Event::fake([MatchDisputeResolved::class]);

        // Side 2 is disqualified: the match is forfeited 1:0 to side 1. Before
        // the fix it finished still "disputed", on the desk's panel and in the
        // open-disputes alert.
        app(DisqualifyEntrant::class)->handle(integrityTestEntrant($sides[2]));

        $settled = $match->fresh();

        expect($settled->state)->toBe(MatchState::Finished)
            ->and($settled->winner_entrant_id)->toBe($sides[1])
            ->and($settled->isDisputed())->toBeFalse()
            ->and($settled->history()->get()->map(fn (MatchHistoryEntry $entry): string => $entry->kind->value)->all())
            ->toBe(['reported', 'reported', 'disputed', 'resolved', 'recorded']);

        Event::assertNotDispatched(MatchDisputeResolved::class);
    });
});

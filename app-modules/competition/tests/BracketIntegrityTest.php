<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Competition\Actions\ConfirmMatchResult;
use Konventix\Competition\Actions\CorrectMatchResult;
use Konventix\Competition\Actions\RecordResultAtDesk;
use Konventix\Competition\Actions\ReportMatchResult;
use Konventix\Competition\Actions\ResolveDispute;
use Konventix\Competition\Data\MatchReportData;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Exceptions\ResultSubmissionException;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchResultReport;

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

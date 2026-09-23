<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Competition\Actions\ConfirmMatchResult;
use Konventix\Competition\Actions\DisputeMatchResult;
use Konventix\Competition\Actions\RecordMatchResult;
use Konventix\Competition\Actions\RecordResultAtDesk;
use Konventix\Competition\Actions\ReportMatchResult;
use Konventix\Competition\Actions\ResolveDispute;
use Konventix\Competition\Actions\StoreProofImage;
use Konventix\Competition\Data\MatchReportData;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\MatchHistoryKind;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\ProofPolicy;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Events\MatchDisputed;
use Konventix\Competition\Events\MatchDisputeResolved;
use Konventix\Competition\Events\MatchFinished;
use Konventix\Competition\Events\MatchResultCorrected;
use Konventix\Competition\Events\MatchResultReported;
use Konventix\Competition\Exceptions\ResultSubmissionException;
use Konventix\Competition\Gdpr\CompetitionGdprHandler;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchHistoryEntry;
use Konventix\Competition\Models\MatchResultReport;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\MediaItem;

/**
 * Results reported by participants, confirmed by their opponents, disputed
 * when they disagree, and resolved by the desk (design §8.1, §8.2, §8.4,
 * §8.6, §8.7).
 *
 * The correction cascade of §8.5 is `CorrectionCascadeTest`, which needs a
 * whole bracket to have anything to cascade into. This file needs one match.
 *
 * Helper names prefixed — Pest helpers are process-global (.ai/rules/tests.md).
 * The shared field-and-roster builders live in `tests/Helpers.php` because
 * both of §8's files call them.
 */

/**
 * A two-entrant single-elimination stage: one generated match, two sides,
 * each with a member who may speak for it.
 *
 * @return array{0: Competition, 1: CompetitionMatch, 2: array<int, string>, 3: array<string, string>}
 *                                                                                                     competition, the match, `position => entrant ULID`, `entrant ULID => user ULID`
 */
function reportTestMatch(
    ResultSubmissionMode $mode = ResultSubmissionMode::Participants,
    ProofPolicy $proofPolicy = ProofPolicy::None,
): array {
    [$competition, , $members] = competitionFieldWithRosters(2, [
        'result_submission_mode' => $mode,
        'proof_policy' => $proofPolicy,
    ]);

    $stage = competitionGeneratedStage($competition);
    $match = competitionMatchAt($stage, 1, 1);

    return [$competition, $match, competitionSidesOf($match), $members];
}

function reportTestEntrant(string $entrantId): Entrant
{
    /** @var Entrant $entrant */
    $entrant = Entrant::query()->findOrFail($entrantId);

    return $entrant;
}

/**
 * @return list<string> the kinds written for a match, oldest first
 */
function reportTestHistoryKinds(CompetitionMatch $match): array
{
    // Through the model's own relation, so the order asserted is the order
    // the timeline renders in (§8.6) — not one the test imposes on top.
    return $match->history()
        ->get()
        ->map(static fn (MatchHistoryEntry $entry): string => $entry->kind->value)
        ->all();
}

describe('reporting and confirming (§8.2)', function (): void {
    it('finalises the match when the other side confirms', function (): void {
        $this->withBrand();

        [, $match, $sides, $members] = reportTestMatch();

        Event::fake([MatchFinished::class, MatchResultReported::class]);

        app(ReportMatchResult::class)->handle(
            $match,
            reportTestEntrant($sides[1]),
            new MatchReportData(scores: [1 => 16, 2 => 9]),
            $members[$sides[1]],
        );

        // Nothing is recorded on one claim alone: the match is only
        // *reported*.
        expect($match->fresh()->state)->toBe(MatchState::Pending)
            ->and($match->fresh()->reported_at)->not->toBeNull();

        $finished = app(ConfirmMatchResult::class)->handle(
            $match->fresh(),
            reportTestEntrant($sides[2]),
            $members[$sides[2]],
        );

        expect($finished->state)->toBe(MatchState::Finished)
            ->and($finished->winner_entrant_id)->toBe($sides[1])
            ->and($finished->finished_event_emitted_at)->not->toBeNull();

        // The agreed scores are the ones that landed on the slots: 16 and 9,
        // in the positions the report named them.
        $scores = MatchSlot::query()
            ->where('competition_match_id', $match->getKey())
            ->orderBy('position')
            ->pluck('score')
            ->all();

        expect($scores)->toBe([16, 9])
            ->and(reportTestHistoryKinds($match))->toBe(['reported', 'confirmed', 'recorded']);

        Event::assertDispatchedTimes(MatchFinished::class, 1);
        Event::assertDispatched(MatchResultReported::class);
    });

    it('treats a second report that says the same thing as agreement', function (): void {
        // §8.2 draws confirming and counter-reporting as two branches, but a
        // counter-report that agrees *is* a confirmation — and a match stuck
        // because both sides pressed "report" would be a bug, not a rule.
        $this->withBrand();

        [, $match, $sides, $members] = reportTestMatch();

        foreach ([1, 2] as $position) {
            app(ReportMatchResult::class)->handle(
                $match->fresh(),
                reportTestEntrant($sides[$position]),
                new MatchReportData(scores: [1 => 13, 2 => 16]),
                $members[$sides[$position]],
            );
        }

        expect($match->fresh()->state)->toBe(MatchState::Finished)
            // Position 2 scored 16 against 13, so position 2 won.
            ->and($match->fresh()->winner_entrant_id)->toBe($sides[2])
            ->and($match->fresh()->disputed_at)->toBeNull();
    });

    it('replaces a side\'s own earlier claim rather than filing a second one', function (): void {
        $this->withBrand();

        [, $match, $sides, $members] = reportTestMatch();

        app(ReportMatchResult::class)->handle(
            $match,
            reportTestEntrant($sides[1]),
            new MatchReportData(scores: [1 => 16, 2 => 9]),
            $members[$sides[1]],
        );

        app(ReportMatchResult::class)->handle(
            $match->fresh(),
            reportTestEntrant($sides[1]),
            new MatchReportData(scores: [1 => 16, 2 => 14]),
            $members[$sides[1]],
        );

        $reports = MatchResultReport::query()->where('competition_match_id', $match->getKey())->get();

        expect($reports)->toHaveCount(1)
            ->and($reports->first()->normalisedScores())->toBe([1 => 16, 2 => 14])
            ->and($match->fresh()->disputed_at)->toBeNull();
    });

    it('refuses a participant report when the competition is desk_only', function (): void {
        // §8.1's first knob, enforced in the Action: a client that ignores
        // the interface must still be refused.
        $this->withBrand();

        [, $match, $sides, $members] = reportTestMatch(ResultSubmissionMode::DeskOnly);

        expect(fn () => app(ReportMatchResult::class)->handle(
            $match,
            reportTestEntrant($sides[1]),
            new MatchReportData(scores: [1 => 16, 2 => 9]),
            $members[$sides[1]],
        ))->toThrow(ResultSubmissionException::class);

        // The desk records directly, as §8.2 step 3 says it does.
        $recorded = app(RecordMatchResult::class)->handle($match->fresh(), [$sides[1] => 16, $sides[2] => 9], $sides[1]);

        expect($recorded->state)->toBe(MatchState::Finished);
    });

    it('refuses a report on a match that already has a recorded result', function (): void {
        // §8.2, point 4. From here only a correction changes anything.
        $this->withBrand();

        [, $match, $sides, $members] = reportTestMatch();

        app(RecordMatchResult::class)->handle($match, [$sides[1] => 16, $sides[2] => 9], $sides[1]);

        expect(fn () => app(ReportMatchResult::class)->handle(
            $match->fresh(),
            reportTestEntrant($sides[1]),
            new MatchReportData(scores: [1 => 9, 2 => 16]),
            $members[$sides[1]],
        ))->toThrow(ResultSubmissionException::class);
    });

    it('refuses somebody who is not on the side they report for', function (): void {
        $this->withBrand();

        [, $match, $sides] = reportTestMatch();

        expect(fn () => app(ReportMatchResult::class)->handle(
            $match,
            reportTestEntrant($sides[1]),
            new MatchReportData(scores: [1 => 16, 2 => 9]),
            (string) Str::ulid(),
        ))->toThrow(ResultSubmissionException::class);
    });

    it('refuses an entrant that is not a side of the match', function (): void {
        $this->withBrand();

        [, $match, $sides, $members] = reportTestMatch();

        [, $otherField, $otherMembers] = competitionFieldWithRosters(1, [
            'result_submission_mode' => ResultSubmissionMode::Participants,
        ]);

        $outsider = $otherField[0];

        expect(fn () => app(ReportMatchResult::class)->handle(
            $match,
            $outsider,
            new MatchReportData(scores: [1 => 16, 2 => 9]),
            $otherMembers[$outsider->getKey()],
        ))->toThrow(ResultSubmissionException::class);

        // The match is untouched: no claim, no `reported_at`.
        expect(MatchResultReport::query()->where('competition_match_id', $match->getKey())->count())->toBe(0)
            ->and($match->fresh()->reported_at)->toBeNull()
            ->and($members)->toHaveCount(2);
    });

    it('refuses a score line that does not cover every side', function (): void {
        $this->withBrand();

        [, $match, $sides, $members] = reportTestMatch();

        expect(fn () => app(ReportMatchResult::class)->handle(
            $match,
            reportTestEntrant($sides[1]),
            new MatchReportData(scores: [1 => 16]),
            $members[$sides[1]],
        ))->toThrow(ResultSubmissionException::class);
    });

    it('refuses a report while the other side is still to come, and takes it once they arrive', function (): void {
        // The desk's rule (`RecordResultAtDesk`, fix round of the desk task),
        // now on the participant's door too: a claim against nobody would be
        // the only report on the match when the opponent finally arrives.
        $this->withBrand();

        // Three entrants in a bracket of four. S(4) = [1, 4, 2, 3]:
        //   round 1, match 1: seed 1 ($a) v seed 4 — absent, a bye
        //   round 1, match 2: seed 2 ($b) v seed 3 ($c)
        //   round 2, match 1: slot 1 from match 1, slot 2 from match 2
        [$competition, [$a, $b, $c], $members] = competitionFieldWithRosters(3, [
            'result_submission_mode' => ResultSubmissionMode::Participants,
        ]);
        $stage = competitionGeneratedStage($competition);
        $desk = (string) Str::ulid();

        expect(competitionSidesOf(competitionMatchAt($stage, 1, 1)))->toBe([1 => $a->id])
            ->and(competitionSidesOf(competitionMatchAt($stage, 1, 2)))->toBe([1 => $b->id, 2 => $c->id]);

        // The bye is settled by the desk; $a moves to the final's slot 1.
        app(RecordResultAtDesk::class)->handle(competitionMatchAt($stage, 1, 1), [1 => 0], $a->id, $desk);

        $final = competitionMatchAt($stage, 2, 1);
        expect(competitionSidesOf($final))->toBe([1 => $a->id]);

        // Slot 2 still waits on $b v $c, so $a's claim is refused and leaves
        // no trace on the match.
        expect(fn () => app(ReportMatchResult::class)->handle(
            $final,
            $a,
            new MatchReportData(scores: [1 => 16]),
            $members[$a->id],
        ))->toThrow(ResultSubmissionException::class);

        expect(MatchResultReport::query()->where('competition_match_id', $final->getKey())->count())->toBe(0)
            ->and($final->fresh()->reported_at)->toBeNull()
            ->and(reportTestHistoryKinds($final->fresh()))->toBe([]);

        // $b beats $c 2:0 and fills slot 2; now both sides are there and the
        // same person's report on the final is filed.
        app(RecordResultAtDesk::class)->handle(competitionMatchAt($stage, 1, 2), [1 => 2, 2 => 0], $b->id, $desk);

        $final = competitionMatchAt($stage, 2, 1);
        expect(competitionSidesOf($final))->toBe([1 => $a->id, 2 => $b->id]);

        app(ReportMatchResult::class)->handle(
            $final,
            $a,
            new MatchReportData(scores: [1 => 16, 2 => 9]),
            $members[$a->id],
        );

        expect($final->fresh()->reported_at)->not->toBeNull()
            ->and(MatchResultReport::query()->where('competition_match_id', $final->getKey())->count())->toBe(1);
    });

    it('refuses to confirm when nobody has reported, and refuses to confirm one\'s own claim', function (): void {
        $this->withBrand();

        [, $match, $sides, $members] = reportTestMatch();

        expect(fn () => app(ConfirmMatchResult::class)->handle(
            $match,
            reportTestEntrant($sides[2]),
            $members[$sides[2]],
        ))->toThrow(ResultSubmissionException::class);

        app(ReportMatchResult::class)->handle(
            $match,
            reportTestEntrant($sides[1]),
            new MatchReportData(scores: [1 => 16, 2 => 9]),
            $members[$sides[1]],
        );

        expect(fn () => app(ConfirmMatchResult::class)->handle(
            $match->fresh(),
            reportTestEntrant($sides[1]),
            $members[$sides[1]],
        ))->toThrow(ResultSubmissionException::class);
    });
});

describe('disputes (§8.4)', function (): void {
    it('opens a dispute when the two sides disagree, and keeps both claims', function (): void {
        $this->withBrand();

        [, $match, $sides, $members] = reportTestMatch();

        Event::fake([MatchDisputed::class, MatchFinished::class]);

        app(ReportMatchResult::class)->handle(
            $match,
            reportTestEntrant($sides[1]),
            new MatchReportData(scores: [1 => 16, 2 => 9]),
            $members[$sides[1]],
        );

        app(ReportMatchResult::class)->handle(
            $match->fresh(),
            reportTestEntrant($sides[2]),
            new MatchReportData(scores: [1 => 9, 2 => 16]),
            $members[$sides[2]],
        );

        $disputed = $match->fresh();

        expect($disputed->isDisputed())->toBeTrue()
            ->and($disputed->dispute_resolved_at)->toBeNull()
            // Nothing is recorded while the sides disagree.
            ->and($disputed->state)->toBe(MatchState::Pending)
            ->and($disputed->winner_entrant_id)->toBeNull()
            // Both claims stay: the desk decides on what each side said.
            ->and(MatchResultReport::query()->where('competition_match_id', $match->getKey())->count())->toBe(2)
            ->and(reportTestHistoryKinds($match))->toBe(['reported', 'reported', 'disputed']);

        Event::assertDispatched(MatchDisputed::class);
        Event::assertNotDispatched(MatchFinished::class);
    });

    it('announces no report that the dispute path rolled back', function (): void {
        // A side re-reporting a still-contradicting score on a match that is
        // already disputed reaches `DisputeMatchResult`, which refuses — and
        // the whole report rolls back with it. `MatchResultReported` waits
        // for the commit, so a report that was never filed is never
        // announced (.ai/rules/src.md).
        $this->withBrand();

        [, $match, $sides, $members] = reportTestMatch();

        app(ReportMatchResult::class)->handle(
            $match,
            reportTestEntrant($sides[1]),
            new MatchReportData(scores: [1 => 16, 2 => 9]),
            $members[$sides[1]],
        );

        app(ReportMatchResult::class)->handle(
            $match->fresh(),
            reportTestEntrant($sides[2]),
            new MatchReportData(scores: [1 => 9, 2 => 16]),
            $members[$sides[2]],
        );

        expect($match->fresh()->isDisputed())->toBeTrue();

        Event::fake([MatchResultReported::class]);

        expect(fn () => app(ReportMatchResult::class)->handle(
            $match->fresh(),
            reportTestEntrant($sides[1]),
            new MatchReportData(scores: [1 => 16, 2 => 10]),
            $members[$sides[1]],
        ))->toThrow(ResultSubmissionException::class);

        Event::assertNotDispatched(MatchResultReported::class);

        // Rolled back: side 1's stored claim is still the first one.
        expect(MatchResultReport::query()
            ->where('competition_match_id', $match->getKey())
            ->where('entrant_id', $sides[1])
            ->sole()
            ->normalisedScores())->toBe([1 => 16, 2 => 9]);
    });

    it('refuses a dispute once the competition is finished or cancelled', function (): void {
        // A dispute opened on a tournament nobody runs any more would sit in
        // the desk's queue forever, and resolving it would void results in a
        // bracket whose standings are already published. No time window is
        // invented — §8.4 states none — the competition's own end is the end.
        $this->withBrand();

        foreach ([CompetitionStatus::Finished, CompetitionStatus::Cancelled] as $status) {
            [$competition, $match, $sides, $members] = reportTestMatch();

            app(RecordMatchResult::class)->handle($match, [$sides[1] => 16, $sides[2] => 9], $sides[1]);

            $competition->forceFill(['status' => $status])->save();

            expect(fn () => app(DisputeMatchResult::class)->handle(
                $match->fresh(),
                reportTestEntrant($sides[2]),
                'Das Ergebnis stimmt nicht.',
                $members[$sides[2]],
            ))->toThrow(ResultSubmissionException::class);

            expect($match->fresh()->disputed_at)->toBeNull();
        }
    });

    it('refuses a dispute with no reason', function (): void {
        $this->withBrand();

        [, $match, $sides, $members] = reportTestMatch();

        app(ReportMatchResult::class)->handle(
            $match,
            reportTestEntrant($sides[1]),
            new MatchReportData(scores: [1 => 16, 2 => 9]),
            $members[$sides[1]],
        );

        expect(fn () => app(DisputeMatchResult::class)->handle(
            $match->fresh(),
            reportTestEntrant($sides[2]),
            '   ',
            $members[$sides[2]],
        ))->toThrow(ValidationException::class);
    });

    it('resolves a dispute only with a note, and records the result the desk decided', function (): void {
        // §8.4: "records the result the organizer decides on and **requires a
        // `resolution_note`**, which lands in the match history (§8.6) and is
        // shown to both sides."
        $this->withBrand();

        [, $match, $sides, $members] = reportTestMatch();

        Event::fake([MatchFinished::class, MatchDisputeResolved::class]);

        app(ReportMatchResult::class)->handle(
            $match,
            reportTestEntrant($sides[1]),
            new MatchReportData(scores: [1 => 16, 2 => 9]),
            $members[$sides[1]],
        );

        app(ReportMatchResult::class)->handle(
            $match->fresh(),
            reportTestEntrant($sides[2]),
            new MatchReportData(scores: [1 => 9, 2 => 16]),
            $members[$sides[2]],
        );

        $organizer = (string) Str::ulid();

        expect(fn () => app(ResolveDispute::class)->handle(
            $match->fresh(),
            [1 => 16, 2 => 14],
            $sides[1],
            $organizer,
            '',
        ))->toThrow(ValidationException::class);

        // The refusal left the dispute open rather than half resolved.
        expect($match->fresh()->isDisputed())->toBeTrue();

        $resolved = app(ResolveDispute::class)->handle(
            $match->fresh(),
            [1 => 16, 2 => 14],
            $sides[1],
            $organizer,
            'Der Screenshot zeigt 16:14.',
        );

        expect($resolved->state)->toBe(MatchState::Finished)
            ->and($resolved->winner_entrant_id)->toBe($sides[1])
            ->and($resolved->isDisputed())->toBeFalse()
            ->and($resolved->dispute_resolved_at)->not->toBeNull()
            ->and(MatchSlot::query()
                ->where('competition_match_id', $match->getKey())
                ->orderBy('position')
                ->pluck('score')
                ->all())->toBe([16, 14]);

        /** @var MatchHistoryEntry $entry */
        $entry = MatchHistoryEntry::query()
            ->where('competition_match_id', $match->getKey())
            ->where('kind', MatchHistoryKind::Resolved->value)
            ->sole();

        expect($entry->payloadData()->note)->toBe('Der Screenshot zeigt 16:14.')
            ->and($entry->actor_user_id)->toBe($organizer);

        Event::assertDispatchedTimes(MatchFinished::class, 1);
        Event::assertDispatched(MatchDisputeResolved::class);
    });

    it('lets a side contest a recorded result, and resolves it as a correction', function (): void {
        // §8.4's second capability — "`CorrectResults` after" — only means
        // something if a recorded result can be contested at all. A report on
        // a finished match is still refused (§8.2, point 4); a dispute is the
        // one thing that still gets through.
        $this->withBrand();

        [, $match, $sides, $members] = reportTestMatch();

        Event::fake([MatchFinished::class, MatchResultCorrected::class, MatchDisputed::class]);

        app(RecordMatchResult::class)->handle($match, [$sides[1] => 16, $sides[2] => 9], $sides[1]);

        app(DisputeMatchResult::class)->handle(
            $match->fresh(),
            reportTestEntrant($sides[2]),
            'Die Runde wurde neu gestartet, das Ergebnis zaehlt nicht.',
            $members[$sides[2]],
        );

        expect($match->fresh()->isDisputed())->toBeTrue()
            // Disputing does not undo the result; the desk does.
            ->and($match->fresh()->state)->toBe(MatchState::Finished);

        $resolved = app(ResolveDispute::class)->handle(
            $match->fresh(),
            [1 => 9, 2 => 16],
            $sides[2],
            (string) Str::ulid(),
            'Neustart bestaetigt; das zweite Ergebnis gilt.',
        );

        expect($resolved->winner_entrant_id)->toBe($sides[2])
            ->and($resolved->state)->toBe(MatchState::Finished)
            ->and($resolved->isDisputed())->toBeFalse();

        // One `MatchFinished` for the original recording and no second one:
        // the desk corrected, it did not re-finish (D-CMP-12).
        Event::assertDispatchedTimes(MatchFinished::class, 1);
        Event::assertDispatchedTimes(MatchResultCorrected::class, 1);

        expect(reportTestHistoryKinds($match))->toBe(['recorded', 'disputed', 'resolved', 'corrected']);
    });

    it('refuses to resolve a match that is not disputed', function (): void {
        $this->withBrand();

        [, $match, $sides] = reportTestMatch();

        expect(fn () => app(ResolveDispute::class)->handle(
            $match,
            [1 => 1, 2 => 0],
            $sides[1],
            (string) Str::ulid(),
            'Nothing to settle.',
        ))->toThrow(ResultSubmissionException::class);
    });
});

describe('proofs (§8.1, §8.7)', function (): void {
    it('refuses a report with no proof when the competition requires one', function (): void {
        $this->withBrand();

        [, $match, $sides, $members] = reportTestMatch(ResultSubmissionMode::Participants, ProofPolicy::Required);

        expect(fn () => app(ReportMatchResult::class)->handle(
            $match,
            reportTestEntrant($sides[1]),
            new MatchReportData(scores: [1 => 16, 2 => 9]),
            $members[$sides[1]],
        ))->toThrow(ValidationException::class);

        // A real item of this brand's library: `ProofGate` refuses an id that
        // names none (see the foreign-id test below).
        $proofId = (string) MediaItem::factory()->create()->getKey();

        app(ReportMatchResult::class)->handle(
            $match->fresh(),
            reportTestEntrant($sides[1]),
            new MatchReportData(scores: [1 => 16, 2 => 9], proofMediaId: $proofId),
            $members[$sides[1]],
        );

        expect(MatchResultReport::query()->where('competition_match_id', $match->getKey())->sole()->proof_media_id)
            ->toBe($proofId);
    });

    it('refuses a proof sent to a competition whose policy is none', function (): void {
        // §8.1's three values are the three answers to "may or must a result
        // carry a proof", so `none` means *may not*. A `none` that accepted
        // and dropped the image would be `optional` under another name.
        $this->withBrand();

        [, $match, $sides, $members] = reportTestMatch(ResultSubmissionMode::Participants, ProofPolicy::None);

        expect(ProofPolicy::None->acceptsProof())->toBeFalse()
            ->and(ProofPolicy::Optional->acceptsProof())->toBeTrue()
            ->and(ProofPolicy::Required->demandsProof())->toBeTrue();

        expect(fn () => app(ReportMatchResult::class)->handle(
            $match,
            reportTestEntrant($sides[1]),
            new MatchReportData(scores: [1 => 16, 2 => 9], proofMediaId: (string) Str::ulid()),
            $members[$sides[1]],
        ))->toThrow(ValidationException::class);
    });

    it('refuses a proof id that is not one of the brand\'s own media items', function (): void {
        // The id is participant-supplied. Another brand's image — or an id
        // naming nothing at all — must not be stored and shown to the other
        // side and the desk as this side's evidence.
        $this->withBrand();

        [, $match, $sides, $members] = reportTestMatch(ResultSubmissionMode::Participants, ProofPolicy::Optional);

        $foreign = (string) MediaItem::factory()->create(['brand_id' => Brand::factory()->create()->getKey()])->getKey();
        $unknown = (string) Str::ulid();

        foreach ([$foreign, $unknown] as $proofMediaId) {
            expect(fn () => app(ReportMatchResult::class)->handle(
                $match->fresh(),
                reportTestEntrant($sides[1]),
                new MatchReportData(scores: [1 => 16, 2 => 9], proofMediaId: $proofMediaId),
                $members[$sides[1]],
            ))->toThrow(ValidationException::class);
        }

        // Refused, so rolled back: no claim filed, nothing reported.
        expect(MatchResultReport::query()->where('competition_match_id', $match->getKey())->count())->toBe(0)
            ->and($match->fresh()->reported_at)->toBeNull();
    });

    it('turns an upload away before a byte is stored when the policy is none', function (): void {
        // The sharp edge of `none`: `proof_media_id` is a ULID, so a guard at
        // submission time fires only after the file is written. The door has
        // to refuse first, or a participant uploads successfully and then
        // loses their whole result over the attachment.
        $this->withBrand();

        Storage::fake('media');

        [$competition, $field, $members] = competitionFieldWithRosters(1, ['proof_policy' => ProofPolicy::None]);

        expect(fn () => app(StoreProofImage::class)->handle(
            $competition,
            $field[0],
            UploadedFile::fake()->image('scoreboard.png'),
            $members[$field[0]->getKey()],
        ))->toThrow(ResultSubmissionException::class);

        expect(Storage::disk('media')->allFiles())->toBe([]);
    });

    it('refuses an upload from somebody who is not on the side', function (): void {
        $this->withBrand();

        Storage::fake('media');

        [$competition, $field] = competitionFieldWithRosters(1, ['proof_policy' => ProofPolicy::Optional]);

        expect(fn () => app(StoreProofImage::class)->handle(
            $competition,
            $field[0],
            UploadedFile::fake()->image('scoreboard.png'),
            (string) Str::ulid(),
        ))->toThrow(ResultSubmissionException::class);

        expect(Storage::disk('media')->allFiles())->toBe([]);
    });

    it('hands an accepted upload to Platform\'s media library', function (): void {
        // ADR-0035: Competition never stores a file. It authorises, and
        // Platform stores — the ULID that comes back is all this module keeps.
        $this->withBrand();

        Storage::fake('media');

        [$competition, $field, $members] = competitionFieldWithRosters(1, ['proof_policy' => ProofPolicy::Required]);

        $mediaId = app(StoreProofImage::class)->handle(
            $competition,
            $field[0],
            UploadedFile::fake()->image('scoreboard.png', 400, 300),
            $members[$field[0]->getKey()],
        );

        expect($mediaId)->toBeString()
            ->and(Storage::disk('media')->allFiles())->not->toBe([]);
    });
});

describe('match history (§8.6)', function (): void {
    it('carries exactly the kinds §8.6 lists, GameOps\' two included', function (): void {
        // The set is closed and shipped whole, so a timeline a page renders
        // does not change shape when GameOps starts writing into it in 0.10.
        expect(array_column(MatchHistoryKind::cases(), 'value'))->toBe([
            'scheduled',
            'rescheduled',
            'checked_in',
            'reported',
            'confirmed',
            'disputed',
            'resolved',
            'recorded',
            'corrected',
            'voided_by_correction',
            'cancelled',
            'session_live',
            'session_failed',
        ]);
    });

    it('writes none of the three reserved kinds while a whole match is played out', function (): void {
        $this->withBrand();

        [, $match, $sides, $members] = reportTestMatch();

        app(ReportMatchResult::class)->handle(
            $match,
            reportTestEntrant($sides[1]),
            new MatchReportData(scores: [1 => 16, 2 => 9]),
            $members[$sides[1]],
        );

        app(ConfirmMatchResult::class)->handle(
            $match->fresh(),
            reportTestEntrant($sides[2]),
            $members[$sides[2]],
        );

        $reserved = array_values(array_filter(
            reportTestHistoryKinds($match),
            static fn (string $kind): bool => ! MatchHistoryKind::from($kind)->isWrittenByCompetition(),
        ));

        expect($reserved)->toBe([]);
    });

    it('names the side and the score on a reported row', function (): void {
        $this->withBrand();

        [, $match, $sides, $members] = reportTestMatch();

        app(ReportMatchResult::class)->handle(
            $match,
            reportTestEntrant($sides[1]),
            new MatchReportData(scores: [1 => 16, 2 => 9], note: 'GG'),
            $members[$sides[1]],
        );

        /** @var MatchHistoryEntry $entry */
        $entry = MatchHistoryEntry::query()
            ->where('competition_match_id', $match->getKey())
            ->where('kind', MatchHistoryKind::Reported->value)
            ->sole();

        $payload = $entry->payloadData();

        expect($payload->entrantId)->toBe($sides[1])
            ->and($payload->scores)->toBe([1 => 16, 2 => 9])
            ->and($payload->note)->toBe('GG')
            ->and($entry->actor_user_id)->toBe($members[$sides[1]]);
    });
});

describe('GDPR erasure of the results trail', function (): void {
    it('forgets who reported and acted, and keeps every report and history row', function (): void {
        // A report and a timeline row are the match's history; the person
        // who filed them is not. Erasure nulls the user ULID and the row
        // stays — Registration's `EntryConditionAssertion` shape.
        $this->withBrand();

        [, $match, $sides, $members] = reportTestMatch();

        $forgotten = $members[$sides[1]];
        $stays = $members[$sides[2]];

        // Both sides report the same score, so the second report settles
        // the match (see 'treats a second report that says the same thing as
        // agreement'). Rows written, by hand from `ReportMatchResult`:
        //   reports: side 1 by $forgotten, side 2 by $stays
        //   history: reported ($forgotten), reported ($stays),
        //            confirmed ($stays), recorded ($stays — `settleMatch()`
        //            passes the reporter on as the recorder)
        foreach ([1, 2] as $position) {
            app(ReportMatchResult::class)->handle(
                $match->fresh(),
                reportTestEntrant($sides[$position]),
                new MatchReportData(scores: [1 => 16, 2 => 9]),
                $members[$sides[$position]],
            );
        }

        expect(reportTestHistoryKinds($match))->toBe(['reported', 'reported', 'confirmed', 'recorded'])
            ->and(MatchHistoryEntry::query()->where('competition_match_id', $match->getKey())->where('actor_user_id', $forgotten)->count())->toBe(1)
            ->and(MatchHistoryEntry::query()->where('competition_match_id', $match->getKey())->where('actor_user_id', $stays)->count())->toBe(3);

        (new CompetitionGdprHandler)->delete(SubjectRef::forUser($forgotten), DeletionMode::Erase);

        $reports = MatchResultReport::query()
            ->where('competition_match_id', $match->getKey())
            ->pluck('reported_by_user_id', 'entrant_id')
            ->all();

        expect($reports)->toEqual([$sides[1] => null, $sides[2] => $stays])
            // Every row survives: the same four kinds, in the same order.
            ->and(reportTestHistoryKinds($match))->toBe(['reported', 'reported', 'confirmed', 'recorded'])
            ->and(MatchHistoryEntry::query()->where('competition_match_id', $match->getKey())->where('actor_user_id', $forgotten)->count())->toBe(0)
            ->and(MatchHistoryEntry::query()->where('competition_match_id', $match->getKey())->whereNull('actor_user_id')->count())->toBe(1)
            // Nobody else's reference moved.
            ->and(MatchHistoryEntry::query()->where('competition_match_id', $match->getKey())->where('actor_user_id', $stays)->count())->toBe(3)
            // And the result the reports produced is untouched.
            ->and($match->fresh()->winner_entrant_id)->toBe($sides[1]);
    });
});

describe('the proof upload route (§8.7)', function (): void {
    it('refuses an unauthenticated upload', function (): void {
        $brand = $this->withBrand();
        $this->activateModule('competition', $brand);

        Storage::fake('media');

        [$competition, $field] = competitionFieldWithRosters(1, ['proof_policy' => ProofPolicy::Optional]);

        $this->withHeader('Accept', 'application/json')
            ->post('/tournaments/'.$competition->getKey().'/proofs', [
                'entrant_id' => $field[0]->getKey(),
                'file' => UploadedFile::fake()->image('scoreboard.png'),
            ])
            ->assertUnauthorized();

        expect(Storage::disk('media')->allFiles())->toBe([]);
    });

    it('refuses an upload to a competition that takes no proofs before a byte is stored', function (): void {
        $brand = $this->withBrand();
        $this->activateModule('competition', $brand);

        Storage::fake('media');

        [$competition, $field] = competitionFieldWithRosters(1, ['proof_policy' => ProofPolicy::None]);

        // Any signed-in account: the policy is asked before the roster is.
        $this->actingAs(User::factory()->create())
            ->withHeader('Accept', 'application/json')
            ->post('/tournaments/'.$competition->getKey().'/proofs', [
                'entrant_id' => $field[0]->getKey(),
                'file' => UploadedFile::fake()->image('scoreboard.png'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        expect(Storage::disk('media')->allFiles())->toBe([]);
    });

    it('throttles the upload at ten a minute', function (): void {
        // `throttle:competition-participant` (Task 7 fix round: the named
        // budget every participant write of this module spends, ten a minute
        // per account): ten requests inside the minute reach the controller,
        // the eleventh is turned away by the middleware. The limiter counts a
        // hit before the request is handled, so the ten refusals below count
        // as much as ten uploads would — which is the point: nothing about
        // the answer makes a request free.
        $brand = $this->withBrand();
        $this->activateModule('competition', $brand);

        Storage::fake('media');

        [$competition, $field] = competitionFieldWithRosters(1, ['proof_policy' => ProofPolicy::None]);
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->actingAs($user)
                ->withHeader('Accept', 'application/json')
                ->post('/tournaments/'.$competition->getKey().'/proofs', [
                    'entrant_id' => $field[0]->getKey(),
                    'file' => UploadedFile::fake()->image('scoreboard.png'),
                ])
                ->assertUnprocessable();
        }

        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post('/tournaments/'.$competition->getKey().'/proofs', [
                'entrant_id' => $field[0]->getKey(),
                'file' => UploadedFile::fake()->image('scoreboard.png'),
            ])
            ->assertTooManyRequests();

        expect(Storage::disk('media')->allFiles())->toBe([]);
    });
});

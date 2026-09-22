<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Competition\Actions\AdvanceBracket;
use Konventix\Competition\Actions\CalculateStandings;
use Konventix\Competition\Actions\CorrectMatchResult;
use Konventix\Competition\Actions\RecordMatchResult;
use Konventix\Competition\Enums\MatchHistoryKind;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Events\MatchFinished;
use Konventix\Competition\Events\MatchResultCorrected;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\MatchHistoryEntry;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Models\Standing;

/**
 * The correction cascade (design §8.5), and the promise GameOps is being
 * built on in 0.10 (D-CMP-12).
 *
 * A correction that changes who won does not stop at the match it corrects:
 * the progression graph says who now stands in which downstream slot, and
 * every downstream match that was **played on the old result** is voided —
 * scores cleared, winner cleared, state back to `pending`, a
 * `voided_by_correction` history row carrying the organizer's mandatory note,
 * and the standings recomputed.
 *
 * **The one column a void does not touch is `finished_event_emitted_at`**, and
 * the last test in this file is the reason this whole slice exists in the
 * order it does: a voided match that is played again records a new result and
 * advances the bracket, and emits **no** second `MatchFinished`. GameOps tears
 * a deployment down on that event and is not owed a second teardown.
 *
 * Every expected placement below is worked out by hand in the comment beside
 * it, against `SingleEliminationStandingsCalculator`'s stated rules: the
 * final's winner is 1st and its loser 2nd, and everyone eliminated in the
 * same round shares one placement (there is no 4th in a four-bracket, because
 * no match decided it).
 *
 * Helper names prefixed — Pest helpers are process-global (.ai/rules/tests.md).
 */

/**
 * Plays a match for the entrant standing in `$winningPosition`, with a 2:0
 * score line, and advances the bracket. Returns the winner's ULID.
 */
function cascadeTestPlay(CompetitionMatch $match, int $winningPosition = 1): string
{
    $sides = competitionSidesOf($match);
    $winnerId = $sides[$winningPosition];

    $scores = [];

    foreach ($sides as $position => $entrantId) {
        $scores[$entrantId] = $position === $winningPosition ? 2 : 0;
    }

    app(RecordMatchResult::class)->handle($match, $scores, $winnerId);
    app(AdvanceBracket::class)->handle($match->fresh());

    return $winnerId;
}

/**
 * @return array<string, int> entrant ULID => placement
 */
function cascadeTestPlacements(Stage $stage): array
{
    return Standing::query()
        ->where('stage_id', $stage->getKey())
        ->pluck('placement', 'entrant_id')
        ->all();
}

/**
 * @return list<string> the kinds written for a match, oldest first
 */
function cascadeTestHistoryKinds(CompetitionMatch $match): array
{
    // Through the model's own relation, so the order asserted is the order
    // the timeline renders in (§8.6) — not one the test imposes on top.
    return $match->history()
        ->get()
        ->map(static fn (MatchHistoryEntry $entry): string => $entry->kind->value)
        ->all();
}

/**
 * @return list<int|null> the slot scores of a match, in position order
 */
function cascadeTestScores(CompetitionMatch $match): array
{
    return MatchSlot::query()
        ->where('competition_match_id', $match->getKey())
        ->orderBy('position')
        ->pluck('score')
        ->all();
}

it('voids the downstream result, recomputes the standings, and never re-emits MatchFinished', function (): void {
    $this->withBrand();

    [$competition, $field] = competitionFieldWithRosters(4);
    $stage = competitionGeneratedStage($competition);

    $first = competitionMatchAt($stage, 1, 1);
    $second = competitionMatchAt($stage, 1, 2);
    $final = competitionMatchAt($stage, 2, 1);

    // Read the sides out rather than assume the seeding: which seed the
    // generator puts where is `BracketMathematicsTest`'s subject.
    [$a, $b] = array_values(competitionSidesOf($first));
    [$c, $d] = array_values(competitionSidesOf($second));

    Event::fake([MatchFinished::class, MatchResultCorrected::class]);

    cascadeTestPlay($first);   // A beats B, 2:0
    cascadeTestPlay($second);  // C beats D, 2:0
    cascadeTestPlay($final);   // A beats C, 2:0

    app(CalculateStandings::class)->handle($stage->fresh());

    // By hand, with `final_round` = 2 (the stage's deepest round):
    //   the final's winner A is 1st, its loser C is 2nd;
    //   round 1's losers are B (from match 1) and D (from match 2) — one
    //   tier, so both are 3rd and there is no 4th.
    // `toEqual` rather than `toBe`: the table is read without an ORDER BY,
    // so the row order is the database's business and only the pairs matter.
    expect(cascadeTestPlacements($stage))->toEqual([$a => 1, $c => 2, $b => 3, $d => 3]);

    Event::assertDispatchedTimes(MatchFinished::class, 3);

    // The desk finds the first match was scored the wrong way round.
    $organizer = (string) Str::ulid();

    $corrected = app(CorrectMatchResult::class)->handle(
        $first->fresh(),
        [$a => 1, $b => 2],
        $b,
        correctedByUserId: $organizer,
        note: 'Der Screenshot zeigt 1:2 fuer die andere Seite.',
    );

    expect($corrected->winner_entrant_id)->toBe($b)
        ->and($corrected->state)->toBe(MatchState::Finished);

    Event::assertDispatchedTimes(MatchResultCorrected::class, 1);

    // 1. The bracket was re-routed: B now stands where A stood.
    expect(competitionSidesOf($final->fresh()))->toEqual([1 => $b, 2 => $c]);

    // 2. The final was played on the old result, so it is voided — but its
    //    `finished_event_emitted_at` survives, which is the whole of D-CMP-12.
    $voided = $final->fresh();

    expect($voided->state)->toBe(MatchState::Pending)
        ->and($voided->winner_entrant_id)->toBeNull()
        ->and($voided->finished_at)->toBeNull()
        ->and($voided->finished_event_emitted_at)->not->toBeNull()
        ->and(cascadeTestScores($voided))->toBe([null, null])
        ->and(cascadeTestHistoryKinds($voided))->toBe(['recorded', 'voided_by_correction']);

    /** @var MatchHistoryEntry $voidRow */
    $voidRow = MatchHistoryEntry::query()
        ->where('competition_match_id', $final->getKey())
        ->where('kind', MatchHistoryKind::VoidedByCorrection->value)
        ->sole();

    expect($voidRow->payloadData()->note)->toBe('Der Screenshot zeigt 1:2 fuer die andere Seite.')
        ->and($voidRow->payloadData()->sourceMatchId)->toBe($first->getKey())
        ->and($voidRow->actor_user_id)->toBe($organizer);

    // 3. The standings were recomputed, and by hand they are now empty: the
    //    calculator sees finished matches only, the final is no longer one of
    //    them, and "the final has not been decided" means nothing has a final
    //    placement.
    expect(cascadeTestPlacements($stage))->toBe([]);

    // 4. The final is replayed — and GameOps hears nothing. This is the test
    //    a sister module's 0.10 work is built on.
    app(RecordMatchResult::class)->handle($final->fresh(), [$b => 0, $c => 2], $c);

    Event::assertDispatchedTimes(MatchFinished::class, 3);

    app(CalculateStandings::class)->handle($stage->fresh());

    // By hand again: C wins the final so C is 1st and B is 2nd; round 1's
    // losers are now A (B beat A) and D, sharing 3rd.
    expect(cascadeTestPlacements($stage))->toEqual([$c => 1, $b => 2, $a => 3, $d => 3]);
});

it('refuses a correction that would void a played match without a note', function (): void {
    // §8.5 makes the note mandatory on the voided row, so the walk refuses the
    // moment it finds a result it would have to take away without a reason.
    $this->withBrand();

    [$competition] = competitionFieldWithRosters(4);
    $stage = competitionGeneratedStage($competition);

    $first = competitionMatchAt($stage, 1, 1);
    $second = competitionMatchAt($stage, 1, 2);
    $final = competitionMatchAt($stage, 2, 1);

    [$a, $b] = array_values(competitionSidesOf($first));

    cascadeTestPlay($first);
    cascadeTestPlay($second);
    cascadeTestPlay($final);

    Event::fake([MatchResultCorrected::class]);

    expect(fn () => app(CorrectMatchResult::class)->handle(
        $first->fresh(),
        [$a => 1, $b => 2],
        $b,
        correctedByUserId: (string) Str::ulid(),
    ))->toThrow(ValidationException::class);

    // The refusal rolled the whole correction back: the first match still has
    // its old winner and the final still has its result.
    expect($first->fresh()->winner_entrant_id)->toBe($a)
        ->and($final->fresh()->state)->toBe(MatchState::Finished);

    expect(competitionSidesOf($final->fresh())[1])->toBe($a);

    // And nobody was told: the event waits for the commit, and a correction
    // that rolled back never commits (.ai/rules/src.md).
    Event::assertNotDispatched(MatchResultCorrected::class);

    // The same correction with a note commits, and goes out exactly once —
    // so the silence above is the rollback, not a dispatch that never fires.
    app(CorrectMatchResult::class)->handle(
        $first->fresh(),
        [$a => 1, $b => 2],
        $b,
        correctedByUserId: (string) Str::ulid(),
        note: 'Falscher Sieger eingetragen.',
    );

    Event::assertDispatchedTimes(MatchResultCorrected::class, 1);
});

it('voids nothing when the correction leaves the winner standing', function (): void {
    // A rewritten score with the same winner moves no one in the bracket, so
    // there is nothing downstream to take away and no note is owed — but the
    // standings are still recomputed, because points and tiebreakers moved.
    $this->withBrand();

    [$competition] = competitionFieldWithRosters(4);
    $stage = competitionGeneratedStage($competition);

    $first = competitionMatchAt($stage, 1, 1);
    $second = competitionMatchAt($stage, 1, 2);
    $final = competitionMatchAt($stage, 2, 1);

    [$a, $b] = array_values(competitionSidesOf($first));
    [$c, $d] = array_values(competitionSidesOf($second));

    cascadeTestPlay($first);   // A beats B
    cascadeTestPlay($second);  // C beats D
    cascadeTestPlay($final);   // A beats C

    // 2:1 instead of 2:0 — a different score line, the same winner.
    app(CorrectMatchResult::class)->handle($first->fresh(), [$a => 2, $b => 1], $a);

    expect($final->fresh()->state)->toBe(MatchState::Finished)
        ->and($final->fresh()->winner_entrant_id)->toBe($a)
        // No `voided_by_correction` row: nothing downstream moved.
        ->and(cascadeTestHistoryKinds($final->fresh()))->toBe(['recorded'])
        // The corrected match's own score line is the new one.
        ->and(cascadeTestScores($first->fresh()))->toBe([2, 1]);

    // By hand, unchanged from before the correction: A won the final so A is
    // 1st and C is 2nd; B and D went out in round one and share 3rd.
    expect(cascadeTestPlacements($stage))->toEqual([$a => 1, $c => 2, $b => 3, $d => 3]);
});

it('walks the whole tail of the bracket, not just the next round', function (): void {
    // Two rounds downstream of a corrected round-one match, both played, both
    // voided — and both keeping the record that they already emitted.
    $this->withBrand();

    [$competition] = competitionFieldWithRosters(8);
    $stage = competitionGeneratedStage($competition);

    foreach ([1, 2, 3, 4] as $position) {
        cascadeTestPlay(competitionMatchAt($stage, 1, $position));
    }

    foreach ([1, 2] as $position) {
        cascadeTestPlay(competitionMatchAt($stage, 2, $position));
    }

    cascadeTestPlay(competitionMatchAt($stage, 3, 1));

    $first = competitionMatchAt($stage, 1, 1);
    $semi = competitionMatchAt($stage, 2, 1);
    $final = competitionMatchAt($stage, 3, 1);

    [$a, $b] = array_values(competitionSidesOf($first));

    // The semifinal and the final are both on A's path: A won the first match
    // and every match after it.
    expect($semi->fresh()->winner_entrant_id)->toBe($a)
        ->and($final->fresh()->winner_entrant_id)->toBe($a);

    app(CorrectMatchResult::class)->handle(
        $first->fresh(),
        [$a => 0, $b => 2],
        $b,
        correctedByUserId: (string) Str::ulid(),
        note: 'Falsche Seite eingetragen.',
    );

    // The semifinal was re-routed and voided.
    expect(competitionSidesOf($semi->fresh())[1])->toBe($b)
        ->and($semi->fresh()->state)->toBe(MatchState::Pending)
        ->and($semi->fresh()->winner_entrant_id)->toBeNull()
        ->and($semi->fresh()->finished_event_emitted_at)->not->toBeNull();

    // And so was the final, one round further on — its slot was fed by a
    // match that no longer has a winner, so it is emptied rather than left
    // holding somebody who never got there.
    expect($final->fresh()->state)->toBe(MatchState::Pending)
        ->and($final->fresh()->winner_entrant_id)->toBeNull()
        ->and($final->fresh()->finished_event_emitted_at)->not->toBeNull()
        ->and(competitionSidesOf($final->fresh()))->not->toHaveKey(1)
        ->and(cascadeTestHistoryKinds($final->fresh()))->toBe(['recorded', 'voided_by_correction']);

    // Nothing has a final placement while the final is unplayed.
    app(CalculateStandings::class)->handle($stage->fresh());

    expect(cascadeTestPlacements($stage))->toBe([]);
});

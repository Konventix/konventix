<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Competition\Actions\Concerns\GuardsCompetitionLifecycle;
use Konventix\Competition\Actions\Concerns\RecomputesStageStandings;
use Konventix\Competition\Data\MatchHistoryPayload;
use Konventix\Competition\Enums\MatchHistoryKind;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Events\MatchResultCorrected;
use Konventix\Competition\Exceptions\IllegalMatchStateTransitionException;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\MatchConnection;
use Konventix\Competition\Models\MatchResultReport;
use Konventix\Competition\Models\MatchSlot;

/**
 * The tournament desk's correction capability (D-CMP-11, D-CMP-12), and the
 * cascade it sets off (design §8.5).
 *
 * Rewrites a finished match's recorded result and emits
 * `MatchResultCorrected` — **never a second `MatchFinished`**. That is the
 * entire mechanism behind "MatchFinished fires exactly once per match": a
 * correction is a different event because it means something different, and
 * because GameOps tears a deployment down on the first one.
 *
 * Only a finished match can be corrected. Correcting a match still in play is
 * not a correction, it is recording a result, and `RecordMatchResult` is the
 * Action for that.
 *
 * The previous winner travels on the event because a consumer that already
 * acted on the original result needs to know what it is undoing, not merely
 * what is now true.
 *
 * ## The cascade (§8.5)
 *
 * A correction that changes who won — or, in double elimination, who lost —
 * does not stop at the match it corrects. The user's words are that a
 * correction "results in new matches/pairings", and the progression graph
 * says exactly which:
 *
 * 1. The corrected match's outgoing edges are **re-routed**: the new winner
 *    (and loser) replace the old ones in the slots they feed. An edge whose
 *    outcome no longer exists — a correction to a drawn or undecided match —
 *    empties its slot instead.
 * 2. Every downstream match that had **a recorded result on a changed slot**
 *    is **voided**: its scores are cleared, its winner is cleared, its state
 *    returns to `pending` or `scheduled`, the claims its sides had filed are
 *    deleted because they were about a match between other teams, and a
 *    `voided_by_correction` history row records the correcting organizer's
 *    **mandatory** note.
 * 3. Voiding a match empties the slots *it* fed in turn, so the walk
 *    continues until it reaches matches that were never played.
 * 4. The standings are recomputed at the end, once, for the whole stage —
 *    never row by row, for the reason `CalculateStandings` gives.
 *
 * **`finished_event_emitted_at` is never cleared by a void.** It is the whole
 * of D-CMP-12: a voided match that is played again records a new result and
 * advances the bracket, and emits **no** second `MatchFinished`, because
 * GameOps' teardown happened once and is not owed again. A sister module's
 * 0.10 work is built on that promise, and `CorrectionCascadeTest` pins it.
 *
 * ## Why the note is checked mid-walk
 *
 * The note is mandatory for a voided row and meaningless without a void, so a
 * correction that changes nobody's bracket position does not need one — and
 * the desk cannot know in advance which it is doing. The walk therefore
 * refuses the moment it finds the first match it would have to void without a
 * reason. Everything runs in one transaction, so a refusal leaves the
 * correction unmade rather than half made.
 */
final class CorrectMatchResult
{
    use GuardsCompetitionLifecycle;
    use RecomputesStageStandings;

    public function __construct(
        private readonly RecordMatchHistory $history,
    ) {}

    /**
     * @param  array<string, int|null>  $scores  entrant ULID => score
     * @param  string|null  $note  why — mandatory once the correction voids a downstream result
     */
    public function handle(
        CompetitionMatch $match,
        array $scores,
        ?string $winnerEntrantId,
        ?string $correctedByUserId = null,
        ?string $note = null,
    ): CompetitionMatch {
        return DB::transaction(function () use ($match, $scores, $winnerEntrantId, $correctedByUserId, $note): CompetitionMatch {
            $this->guardStillBeingPlayed($match->competition_id);

            if ($match->state !== MatchState::Finished) {
                throw IllegalMatchStateTransitionException::forAction($match, 'corrected');
            }

            $previousWinner = $match->winner_entrant_id;
            $previousLoser = $this->loserOf($match);

            foreach ($scores as $entrantId => $score) {
                MatchSlot::withoutBrandScope()
                    ->where('competition_match_id', $match->id)
                    ->where('entrant_id', $entrantId)
                    ->update(['score' => $score]);
            }

            // `finished_event_emitted_at` is deliberately untouched: it is the
            // record that MatchFinished already went out, and a correction
            // must not make it eligible to go out again.
            $match->forceFill(['winner_entrant_id' => $winnerEntrantId])->save();
            $match->refresh();

            $this->history->handle(
                $match,
                MatchHistoryKind::Corrected,
                new MatchHistoryPayload(
                    winnerEntrantId: $winnerEntrantId,
                    previousWinnerEntrantId: $previousWinner,
                    note: $note,
                ),
                $correctedByUserId,
            );

            // After the commit, never before: the cascade below can still
            // refuse (a downstream void without a note) and roll all of this
            // back, and a listener must not be told of a correction that was
            // never made (.ai/rules/src.md).
            $corrected = new MatchResultCorrected(
                matchId: $match->id,
                competitionId: $match->competition_id,
                brandId: $match->brand_id,
                previousWinnerEntrantId: $previousWinner,
                winnerEntrantId: $winnerEntrantId,
                scores: $scores,
                correctedByUserId: $correctedByUserId,
                correctedAt: now()->toIso8601String(),
            );

            DB::afterCommit(static function () use ($corrected): void {
                event($corrected);
            });

            $routingChanged = $previousWinner !== $winnerEntrantId
                || $previousLoser !== $this->loserOf($match);

            if ($routingChanged) {
                $this->cascade($match, $note, $correctedByUserId);
            }

            // Always, not only when the routing changed: a rewritten score
            // moves points, goal difference and Buchholz even when the same
            // side still won.
            $this->recomputeStandings($match);

            return $match->refresh();
        });
    }

    /**
     * Re-route the corrected match's successors and void what was played on
     * the strength of the old result (§8.5).
     */
    private function cascade(CompetitionMatch $match, ?string $note, ?string $correctedByUserId): void
    {
        $queue = $this->reroute($match);
        $seen = [];

        while ($queue !== []) {
            $matchId = array_shift($queue);

            if (isset($seen[$matchId])) {
                continue;
            }

            $seen[$matchId] = true;

            /** @var CompetitionMatch|null $downstream */
            $downstream = CompetitionMatch::withoutBrandScope()->whereKey($matchId)->first();

            if ($downstream === null || ! $this->hasRecordedResult($downstream)) {
                // Never played on the old result, so there is nothing to undo
                // and nothing it can have fed.
                continue;
            }

            if ($note === null || trim($note) === '') {
                throw ValidationException::withMessages([
                    'note' => __('Correcting this result takes away a result that was already played downstream; say why.'),
                ]);
            }

            $this->void($downstream, $match->id, $note, $correctedByUserId);

            $queue = [...$queue, ...$this->reroute($downstream->refresh())];
        }
    }

    /**
     * Push this match's current outcome along its outgoing edges, and return
     * the matches whose slots that changed.
     *
     * `AdvanceBracket` does the filling — one implementation for every format,
     * idempotent, and the place that already knows how to read the graph. What
     * it does not do is **unfill**: it skips an edge whose outcome is null,
     * because "the winner is not known yet" is the normal state of a bracket
     * being played. A correction is the one caller for which a null outcome
     * means the opposite — somebody who *was* in that slot no longer belongs
     * there — so the emptying is done here, beside it, rather than by changing
     * what advancing means for every other caller.
     *
     * @return list<string>
     */
    private function reroute(CompetitionMatch $match): array
    {
        $touched = app(AdvanceBracket::class)->handle($match);

        $loserId = $this->loserOf($match);

        $edges = MatchConnection::withoutBrandScope()
            ->where('brand_id', $match->brand_id)
            ->where('source_match_id', $match->id)
            ->get();

        foreach ($edges as $edge) {
            $expected = $edge->source_outcome === MatchConnection::OUTCOME_WINNER
                ? $match->winner_entrant_id
                : $loserId;

            if ($expected !== null) {
                continue;
            }

            $slot = MatchSlot::withoutBrandScope()
                ->where('brand_id', $match->brand_id)
                ->where('competition_match_id', $edge->target_match_id)
                ->where('position', $edge->target_slot)
                ->first();

            if ($slot === null || $slot->entrant_id === null) {
                continue;
            }

            $slot->forceFill(['entrant_id' => null])->save();
            $touched[] = $edge->target_match_id;
        }

        return array_values(array_unique($touched));
    }

    /**
     * Take a downstream match's result away (§8.5).
     *
     * Everything about the played match goes: the scores, the winner, the
     * finish, and the claims its sides had filed — those were about a match
     * between other teams and confirming one of them afterwards would record
     * a result nobody played. `finished_event_emitted_at` stays, and it is the
     * only column here that does: D-CMP-12 is that GameOps hears
     * `MatchFinished` once per match, and a replay of this match must not be a
     * second teardown.
     */
    private function void(CompetitionMatch $match, string $sourceMatchId, string $note, ?string $correctedByUserId): void
    {
        MatchSlot::withoutBrandScope()
            ->where('competition_match_id', $match->id)
            ->update(['score' => null]);

        MatchResultReport::withoutBrandScope()
            ->where('competition_match_id', $match->id)
            ->delete();

        $match->forceFill([
            // Back to where it was before it was played. A match that had a
            // time keeps it and stays `scheduled`; one that never did returns
            // to `pending`.
            'state' => $match->scheduled_at !== null ? MatchState::Scheduled : MatchState::Pending,
            'winner_entrant_id' => null,
            'finished_at' => null,
            'reported_at' => null,
            'disputed_at' => null,
            'dispute_resolved_at' => null,
        ])->save();

        $this->history->handle(
            $match,
            MatchHistoryKind::VoidedByCorrection,
            new MatchHistoryPayload(note: $note, sourceMatchId: $sourceMatchId),
            $correctedByUserId,
        );

        $match->refresh();

        // A voided match that keeps its time is on again, between the sides
        // the correction put there. `reroute()` filled its slot while it was
        // still `finished`, which announces nothing, so it is announced here,
        // once its state says it will be played (design §14, "§12
        // reconciled"). With a side still to come it waits, as any match does.
        app(ScheduleMatch::class)->announce($match);
    }

    /**
     * Whether this match was played. The widest of the three readings, like
     * `RefusesOnceAResultIsRecorded`: a finished state, a winner, or a slot
     * carrying a score. They should never disagree, and the reading that
     * voids more is the one that cannot leave a stale result standing.
     */
    private function hasRecordedResult(CompetitionMatch $match): bool
    {
        if ($match->state === MatchState::Finished || $match->winner_entrant_id !== null) {
            return true;
        }

        return MatchSlot::withoutBrandScope()
            ->where('competition_match_id', $match->id)
            ->whereNotNull('score')
            ->exists();
    }

    /**
     * The side that did not win. Null when the match had one participant (a
     * bye) or no winner at all — the same rule `AdvanceBracket` uses, since
     * the two have to agree about where a loser goes.
     */
    private function loserOf(CompetitionMatch $match): ?string
    {
        if ($match->winner_entrant_id === null) {
            return null;
        }

        $entrantIds = MatchSlot::withoutBrandScope()
            ->where('brand_id', $match->brand_id)
            ->where('competition_match_id', $match->id)
            ->whereNotNull('entrant_id')
            ->pluck('entrant_id')
            ->all();

        $losers = array_values(array_filter(
            array_map(strval(...), $entrantIds),
            fn (string $id): bool => $id !== $match->winner_entrant_id,
        ));

        return count($losers) === 1 ? $losers[0] : null;
    }
}

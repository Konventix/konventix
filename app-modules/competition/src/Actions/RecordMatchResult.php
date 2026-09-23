<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\GuardsCompetitionLifecycle;
use Konventix\Competition\Data\MatchHistoryPayload;
use Konventix\Competition\Enums\MatchHistoryKind;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Events\MatchFinished;
use Konventix\Competition\Exceptions\IllegalMatchStateTransitionException;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\MatchSlot;

/**
 * Records a match result and finishes the match (D-CMP-11).
 *
 * The decision is explicit that "handler results record directly and advance
 * the bracket" — there is no desk-confirmation gate in front of this. What
 * the desk holds instead is a *correction* capability, which is
 * `CorrectMatchResult`.
 *
 * **`MatchFinished` is emitted at most once per match** (D-CMP-12), guarded
 * by `finished_event_emitted_at` rather than by the state alone. That is what
 * lets a correction rewrite the score without GameOps tearing the same
 * deployment down a second time — the guarantee is structural, not a habit
 * consumers must keep.
 *
 * ## Why the guard is read here and not only written here
 *
 * Until §8.5 there was no way back out of `Finished`, so the state machine
 * alone kept the promise: recording twice was refused, and a correction never
 * moved the state. The correction **cascade** changes that. A downstream match
 * whose sides a correction re-routed is *voided* — its result cleared, its
 * state back to `pending`/`scheduled` — and then **played again**, which is a
 * perfectly legal second `RecordMatchResult` on the same match.
 *
 * `finished_event_emitted_at` survives that void on purpose (§8.5: "MatchFinished
 * is **not** re-emitted for a voided match when it is played again … GameOps'
 * teardown happened once and is not owed a second time"). So this Action asks
 * the column before emitting, and leaves it alone once it is set: the replay
 * writes the new score, finishes the match and advances the bracket, and
 * emits nothing.
 */
final class RecordMatchResult
{
    use GuardsCompetitionLifecycle;

    /**
     * @var list<MatchState>
     */
    private const array LEGAL_FROM_STATES = [MatchState::Pending, MatchState::Scheduled, MatchState::Live];

    public function __construct(
        private readonly RecordMatchHistory $history,
    ) {}

    /**
     * @param  array<string, int|null>  $scores  entrant ULID => score
     */
    public function handle(
        CompetitionMatch $match,
        array $scores,
        ?string $winnerEntrantId,
        ?string $recordedByUserId = null,
    ): CompetitionMatch {
        return DB::transaction(function () use ($match, $scores, $winnerEntrantId, $recordedByUserId): CompetitionMatch {
            $this->guardStillBeingPlayed($match->competition_id);
            $this->guardTransition($match);

            $this->writeScores($match, $scores);

            // The first time a match finishes, and only then, GameOps is
            // owed its one `MatchFinished` (D-CMP-12).
            $owesTheEvent = $match->finished_event_emitted_at === null;

            $match->forceFill([
                'state' => MatchState::Finished,
                'winner_entrant_id' => $winnerEntrantId,
                'finished_at' => now(),
                // Never moved once set: it is the record that the event went
                // out, not the record of when this match last finished.
                'finished_event_emitted_at' => $owesTheEvent ? now() : $match->finished_event_emitted_at,
            ])->save();

            $this->history->handle(
                $match,
                MatchHistoryKind::Recorded,
                new MatchHistoryPayload(
                    scores: $this->scoresByPosition($match, $scores),
                    winnerEntrantId: $winnerEntrantId,
                ),
                $recordedByUserId,
            );

            if ($owesTheEvent) {
                $finished = new MatchFinished(
                    matchId: $match->id,
                    competitionId: $match->competition_id,
                    brandId: $match->brand_id,
                    winnerEntrantId: $winnerEntrantId,
                    scores: $scores,
                    finishedAt: now()->toIso8601String(),
                );

                DB::afterCommit(static function () use ($finished): void {
                    event($finished);
                });
            }

            return $match->refresh();
        });
    }

    /**
     * @param  array<string, int|null>  $scores
     */
    private function writeScores(CompetitionMatch $match, array $scores): void
    {
        foreach ($scores as $entrantId => $score) {
            MatchSlot::withoutBrandScope()
                ->where('competition_match_id', $match->id)
                ->where('entrant_id', $entrantId)
                ->update(['score' => $score]);
        }
    }

    /**
     * The recorded score line as the history reads it: `position => score`,
     * the same shape a report carries (§8.2), so a timeline row and the
     * report above it can be compared without translating either.
     *
     * @param  array<string, int|null>  $scores  entrant ULID => score
     * @return array<int, int|null>
     */
    private function scoresByPosition(CompetitionMatch $match, array $scores): array
    {
        if ($scores === []) {
            return [];
        }

        $byPosition = [];

        $slots = MatchSlot::withoutBrandScope()
            ->where('brand_id', $match->brand_id)
            ->where('competition_match_id', $match->id)
            ->orderBy('position')
            ->get();

        foreach ($slots as $slot) {
            if ($slot->entrant_id !== null && array_key_exists($slot->entrant_id, $scores)) {
                $byPosition[$slot->position] = $scores[$slot->entrant_id];
            }
        }

        return $byPosition;
    }

    private function guardTransition(CompetitionMatch $match): void
    {
        if (! in_array($match->state, self::LEGAL_FROM_STATES, true)) {
            throw IllegalMatchStateTransitionException::forTransition($match, MatchState::Finished);
        }
    }
}

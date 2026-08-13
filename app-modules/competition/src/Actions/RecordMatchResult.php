<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
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
 */
final class RecordMatchResult
{
    /**
     * @var list<MatchState>
     */
    private const array LEGAL_FROM_STATES = [MatchState::Pending, MatchState::Scheduled, MatchState::Live];

    /**
     * @param  array<string, int|null>  $scores  entrant ULID => score
     */
    public function handle(CompetitionMatch $match, array $scores, ?string $winnerEntrantId): CompetitionMatch
    {
        return DB::transaction(function () use ($match, $scores, $winnerEntrantId): CompetitionMatch {
            $this->guardTransition($match);

            $this->writeScores($match, $scores);

            $match->forceFill([
                'state' => MatchState::Finished,
                'winner_entrant_id' => $winnerEntrantId,
                'finished_at' => now(),
                'finished_event_emitted_at' => now(),
            ])->save();

            event(new MatchFinished(
                matchId: $match->id,
                competitionId: $match->competition_id,
                brandId: $match->brand_id,
                winnerEntrantId: $winnerEntrantId,
                scores: $scores,
                finishedAt: now()->toIso8601String(),
            ));

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

    private function guardTransition(CompetitionMatch $match): void
    {
        if (! in_array($match->state, self::LEGAL_FROM_STATES, true)) {
            throw IllegalMatchStateTransitionException::forTransition($match, MatchState::Finished);
        }
    }
}

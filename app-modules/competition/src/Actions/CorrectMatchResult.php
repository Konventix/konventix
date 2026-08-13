<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Events\MatchResultCorrected;
use Konventix\Competition\Exceptions\IllegalMatchStateTransitionException;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\MatchSlot;

/**
 * The tournament desk's correction capability (D-CMP-11, D-CMP-12).
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
 */
final class CorrectMatchResult
{
    /**
     * @param  array<string, int|null>  $scores  entrant ULID => score
     */
    public function handle(
        CompetitionMatch $match,
        array $scores,
        ?string $winnerEntrantId,
        ?string $correctedByUserId = null,
    ): CompetitionMatch {
        return DB::transaction(function () use ($match, $scores, $winnerEntrantId, $correctedByUserId): CompetitionMatch {
            if ($match->state !== MatchState::Finished) {
                throw IllegalMatchStateTransitionException::forAction($match, 'corrected');
            }

            $previousWinner = $match->winner_entrant_id;

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

            event(new MatchResultCorrected(
                matchId: $match->id,
                competitionId: $match->competition_id,
                brandId: $match->brand_id,
                previousWinnerEntrantId: $previousWinner,
                winnerEntrantId: $winnerEntrantId,
                scores: $scores,
                correctedByUserId: $correctedByUserId,
                correctedAt: now()->toIso8601String(),
            ));

            return $match->refresh();
        });
    }
}

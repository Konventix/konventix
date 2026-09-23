<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions\Concerns;

use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\MatchConnection;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Models\Standing;

/**
 * Deletes a stage's drawn bracket (design §7.5): matches, slots,
 * connections, standings, and `generated_at`. The shared half of
 * `ResetStage` and `RegenerateBracket`, which differ only in what the
 * lifecycle lets each of them do (§4): a reset leaves the stage undrawn and
 * so is refused while the competition runs, a redraw puts a new bracket in
 * the same transaction and is not.
 *
 * Refuses once a result is recorded (D-CMP-20). **Seeds are not touched.**
 *
 * Matches are deleted explicitly even though the schema cascades. The cascade
 * is a safety net for a dropped brand, not a mechanism to route a deliberate
 * delete through — and `competition_standings` hangs off the stage rather
 * than off the matches, so it has to be named here anyway: a stage with no
 * matches has no standings, and leaving the old rows would leave a table
 * claiming placements for a bracket that no longer exists.
 */
trait ClearsTheDraw
{
    use RefusesOnceAResultIsRecorded;

    private function clearDraw(Stage $stage): Stage
    {
        $this->guardNoRecordedResult($stage);

        $matchIds = CompetitionMatch::withoutBrandScope()
            ->where('brand_id', $stage->brand_id)
            ->where('stage_id', $stage->id)
            ->pluck('id')
            ->all();

        MatchConnection::withoutBrandScope()
            ->where(fn ($query) => $query
                ->whereIn('source_match_id', $matchIds)
                ->orWhereIn('target_match_id', $matchIds))
            ->delete();

        MatchSlot::withoutBrandScope()->whereIn('competition_match_id', $matchIds)->delete();

        CompetitionMatch::withoutBrandScope()->whereIn('id', $matchIds)->delete();

        Standing::withoutBrandScope()->where('stage_id', $stage->id)->delete();

        $stage->forceFill(['generated_at' => null, 'drawn_entrant_ids' => null])->save();

        return $stage;
    }
}

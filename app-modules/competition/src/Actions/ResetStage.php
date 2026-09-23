<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\RefusesOnceAResultIsRecorded;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\MatchConnection;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Models\Standing;

/**
 * Takes a drawn stage back to undrawn (design §7.5): "`ResetStage` deletes
 * the stage's matches, slots and connections and clears `generated_at`, so a
 * desk that spots a wrong seeding fixes it instead of living with it."
 *
 * The desk's reset button, and the first half of `RegenerateBracket`. Allowed
 * until the first recorded result, the same line D-CMP-20 draws for every
 * redraw: below it the matches are empty shells, above it they are somebody's
 * played match.
 *
 * **Seeds are not touched** — a desk that resets to fix one team's place
 * drags that one team, not all sixteen again.
 *
 * Matches are deleted explicitly even though the schema cascades. The cascade
 * is a safety net for a dropped brand, not a mechanism to route a deliberate
 * delete through — and `competition_standings` hangs off the stage rather
 * than off the matches, so it has to be named here anyway: a stage with no
 * matches has no standings, and leaving the old rows would leave a table
 * claiming placements for a bracket that no longer exists.
 */
final class ResetStage
{
    use RefusesOnceAResultIsRecorded;

    public function handle(Stage $stage): Stage
    {
        return DB::transaction(function () use ($stage): Stage {
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

            $stage->forceFill(['generated_at' => null])->save();

            return $stage;
        });
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\RefusesOnceAResultIsRecorded;
use Konventix\Competition\Brackets\Plan\BracketPlan;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\MatchConnection;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Models\Standing;

/**
 * Redraws a stage that has not been played yet (D-CMP-20, design §7.5).
 *
 * `GenerateBracket` refuses to run twice, and that refusal stays: generating
 * is not idempotent and a second click must not silently truncate a
 * tournament. What §7.5 adds is the deliberate act — a desk that spots a
 * wrong seeding before the first match fixes it here, instead of running the
 * whole competition off a draw it knows is wrong.
 *
 * The line is the first recorded result, not `generated_at`. Below it nothing
 * of value exists: the matches are empty shells, the standings are a table of
 * zeroes, and both are rebuilt from the same field in the same order.
 *
 * **Seeds are not touched.** That is the point of the split from `SeedStage`:
 * a desk that dragged sixteen teams into the order it wanted and then
 * regenerates gets the same order back, not a fresh shuffle. Re-seeding is
 * the other button.
 *
 * Matches are deleted explicitly even though the schema cascades. The cascade
 * is a safety net for a dropped brand, not a mechanism to route a deliberate
 * delete through — and `competition_standings` hangs off the stage rather
 * than off the matches, so it has to be named here anyway.
 */
final class RegenerateBracket
{
    use RefusesOnceAResultIsRecorded;

    public function __construct(
        private readonly GenerateBracket $generate,
    ) {}

    public function handle(Stage $stage): BracketPlan
    {
        return DB::transaction(function () use ($stage): BracketPlan {
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

            // A stage with no matches has no standings. Leaving the old rows
            // would leave a table claiming placements for a bracket that no
            // longer exists.
            Standing::withoutBrandScope()->where('stage_id', $stage->id)->delete();

            $stage->forceFill(['generated_at' => null])->save();

            return $this->generate->handle($stage);
        });
    }
}

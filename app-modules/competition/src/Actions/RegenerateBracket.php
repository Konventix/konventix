<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\ClearsTheDraw;
use Konventix\Competition\Actions\Concerns\GuardsCompetitionLifecycle;
use Konventix\Competition\Brackets\Plan\BracketPlan;
use Konventix\Competition\Models\Stage;

/**
 * Redraws a stage that has not been played yet (D-CMP-20, design §7.5).
 *
 * `GenerateBracket` refuses to run twice, and that refusal stays: generating
 * is not idempotent and a second click must not silently truncate a
 * tournament. What §7.5 adds is the deliberate act — a desk that spots a
 * wrong seeding before the first match fixes it here, instead of running the
 * whole competition off a draw it knows is wrong.
 *
 * It clears the draw (`ClearsTheDraw`, which owns the line: the first
 * recorded result, not `generated_at`) and draws again, in one transaction.
 * Unlike `ResetStage` it is allowed in `Running` as well as in
 * `SignupClosed` (`GuardsCompetitionLifecycle`): the stage is never left
 * undrawn, so §4's "Running requires a generated stage" holds throughout.
 *
 * **Seeds are not touched.** That is the point of the split from `SeedStage`:
 * a desk that dragged sixteen teams into the order it wanted and then
 * regenerates gets the same order back, not a fresh shuffle. Re-seeding is
 * the other button.
 */
final class RegenerateBracket
{
    use ClearsTheDraw;
    use GuardsCompetitionLifecycle;

    public function __construct(
        private readonly GenerateBracket $generate,
    ) {}

    public function handle(Stage $stage): BracketPlan
    {
        return DB::transaction(function () use ($stage): BracketPlan {
            $this->guardDrawIsOpen($stage->competition_id);

            return $this->generate->handle($this->clearDraw($stage));
        });
    }
}

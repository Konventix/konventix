<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\ClearsTheDraw;
use Konventix\Competition\Actions\Concerns\GuardsCompetitionLifecycle;
use Konventix\Competition\Models\Stage;

/**
 * Takes a drawn stage back to undrawn (design §7.5): "`ResetStage` deletes
 * the stage's matches, slots and connections and clears `generated_at`, so a
 * desk that spots a wrong seeding fixes it instead of living with it."
 *
 * The desk's reset button. Allowed until the first recorded result, the line
 * D-CMP-20 draws for every redraw (`ClearsTheDraw`), and only while signup is
 * closed and play has not started: §4 says `Running` **requires** a generated
 * stage, so a running competition is redrawn in one step
 * (`RegenerateBracket`), never emptied (`GuardsCompetitionLifecycle`).
 *
 * **Seeds are not touched** — a desk that resets to fix one team's place
 * drags that one team, not all sixteen again.
 */
final class ResetStage
{
    use ClearsTheDraw;
    use GuardsCompetitionLifecycle;

    public function handle(Stage $stage): Stage
    {
        return DB::transaction(function () use ($stage): Stage {
            $this->guardDrawMayBeEmptied($stage->competition_id);

            return $this->clearDraw($stage);
        });
    }
}

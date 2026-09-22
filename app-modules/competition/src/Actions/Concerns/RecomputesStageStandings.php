<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions\Concerns;

use Konventix\Competition\Actions\CalculateStandings;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Stage;

/**
 * "…and the standings are recomputed" — the clause §8.5 attaches to a
 * correction and §8.2 to an agreed result.
 *
 * A trait rather than a line in each Action because the recompute is the step
 * that is easy to forget and impossible to notice missing: the table simply
 * goes on saying what it said before, which is exactly what a correction was
 * supposed to change.
 *
 * Always the whole stage, never a row — `CalculateStandings` explains why at
 * length, and the short version is that a late correction moves Buchholz for
 * everyone who faced anyone involved.
 */
trait RecomputesStageStandings
{
    /**
     * Recompute the standings of the stage this match belongs to.
     *
     * A match with no stage is 10a-era data that predates the stage model:
     * there is nothing to rank it against, so there is nothing to do.
     */
    protected function recomputeStandings(CompetitionMatch $match): void
    {
        if ($match->stage_id === null) {
            return;
        }

        /** @var Stage|null $stage */
        $stage = Stage::withoutBrandScope()->whereKey($match->stage_id)->first();

        if ($stage === null) {
            return;
        }

        app(CalculateStandings::class)->handle($stage);
    }
}

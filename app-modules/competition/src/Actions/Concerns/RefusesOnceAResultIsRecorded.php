<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions\Concerns;

use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Exceptions\StageDrawRefusedException;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;

/**
 * The line D-CMP-20 draws: **regeneration is allowed until the first result**
 * (design §7.5).
 *
 * Above that line a redraw costs nothing — a desk that spots a wrong seeding
 * should fix it rather than live with it for the whole tournament, which is
 * what the old flat refusal forced. Below it a redraw would orphan results
 * that people actually played for, and no amount of convenience is worth
 * that.
 *
 * "A result" is deliberately the widest of the three things that could mean
 * it: a finished match, a match with a winner, or a slot carrying a score.
 * They should never disagree, and if they ever do, the reading that refuses
 * is the one that does not delete somebody's match.
 *
 * Shared by `SeedStage` and `ResetStage` (and so `RegenerateBracket`) because re-seeding is as
 * destructive as redrawing: `entrants.seed` breaks ties in every standings
 * calculator, so renumbering the field after a result silently rewrites the
 * table the results produced.
 */
trait RefusesOnceAResultIsRecorded
{
    private function guardNoRecordedResult(Stage $stage): void
    {
        if ($this->hasRecordedResult($stage)) {
            throw new StageDrawRefusedException(sprintf(
                'Stage [%s] already has a recorded result; re-seeding or redrawing it would orphan matches that were played.',
                $stage->id,
            ));
        }
    }

    private function hasRecordedResult(Stage $stage): bool
    {
        $matches = CompetitionMatch::withoutBrandScope()
            ->where('brand_id', $stage->brand_id)
            ->where('stage_id', $stage->id);

        $decided = (clone $matches)
            ->where(fn ($query) => $query
                ->where('state', MatchState::Finished->value)
                ->orWhereNotNull('winner_entrant_id'))
            ->exists();

        if ($decided) {
            return true;
        }

        return MatchSlot::withoutBrandScope()
            ->whereIn('competition_match_id', $matches->pluck('id'))
            ->whereNotNull('score')
            ->exists();
    }
}

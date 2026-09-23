<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\ForfeitsOpenMatches;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchSlot;

/**
 * Once a competition has a result, no retired entrant stands in an open match
 * (design §14, fix wave A round 3, M-f).
 *
 * A team that withdraws **before** the first result keeps its slots: the desk
 * can still draw again, and that is the answer. But the first result — a
 * desk record, an agreed report, a disqualification's forfeit — takes the
 * redraw away. A withdrawn team still in the bracket at that moment would
 * strand the start (its changed-field check could never pass) or, once
 * running, sit in matches nobody will play. So every settle path calls this
 * after it has advanced the bracket, and it forfeits every withdrawn or
 * disqualified entrant still in an open match through the disqualification's
 * pass. With no result yet, or nobody to sweep, it does nothing.
 *
 * It loops until nobody is left: a forfeit between two retired teams sends
 * one of them on as the winner, into a match the next pass takes it out of.
 */
final class ForfeitRetiredEntrants
{
    use ForfeitsOpenMatches;

    public function handle(string $competitionId, string $brandId): void
    {
        DB::transaction(function () use ($competitionId, $brandId): void {
            $swept = null;

            while (($entrant = $this->nextRetiredInPlay($competitionId, $brandId)) !== null) {
                if ($swept === null && ! $this->competitionHasARecordedResult($entrant)) {
                    return;
                }

                $this->forfeitOpenMatches($entrant);
                $swept = $entrant;
            }

            if ($swept !== null) {
                $this->recomputeCompetition($swept);
            }
        });
    }

    private function nextRetiredInPlay(string $competitionId, string $brandId): ?Entrant
    {
        /** @var Entrant|null $entrant */
        $entrant = Entrant::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('competition_id', $competitionId)
            ->whereIn('state', [EntrantState::Withdrawn->value, EntrantState::Disqualified->value])
            ->whereIn('id', MatchSlot::withoutBrandScope()
                ->select('entrant_id')
                ->where('brand_id', $brandId)
                ->whereNotNull('entrant_id')
                ->whereIn('competition_match_id', CompetitionMatch::withoutBrandScope()
                    ->select('id')
                    ->where('brand_id', $brandId)
                    ->where('competition_id', $competitionId)
                    ->whereIn('state', [MatchState::Pending->value, MatchState::Scheduled->value, MatchState::Live->value])))
            ->orderBy('id')
            ->first();

        return $entrant;
    }
}

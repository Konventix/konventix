<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\TransitionsCompetitionStatus;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Events\CompetitionFinished;
use Konventix\Competition\Models\Competition;

/**
 * Closes the tournament: `Running → Finished` (D-CMP-15, design §4).
 * Standings are final from here, and the only remaining state change is
 * `EventArchived` freezing it as history — which adds no state of its own.
 *
 * Driven by the organizer today. The automatic call, when the last stage's
 * final result lands, hangs off the result Actions of §8 and comes with
 * them; both paths end up here rather than writing the state themselves.
 */
final class FinishCompetition
{
    use TransitionsCompetitionStatus;

    public function handle(Competition $competition): Competition
    {
        return DB::transaction(function () use ($competition): Competition {
            $competition = $this->transition($competition, CompetitionStatus::Finished);

            DB::afterCommit(static function () use ($competition): void {
                event(new CompetitionFinished(
                    competitionId: $competition->id,
                    eventId: $competition->event_id,
                    disciplineId: $competition->discipline_id,
                    brandId: $competition->brand_id,
                    status: $competition->status->value,
                    at: now()->toIso8601String(),
                ));
            });

            return $competition;
        });
    }
}

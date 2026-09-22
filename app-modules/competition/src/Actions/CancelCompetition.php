<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\TransitionsCompetitionStatus;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Events\CompetitionCancelled;
use Konventix\Competition\Models\Competition;

/**
 * Calls a competition off (D-CMP-15, design §4). Reachable from `Draft`,
 * `Published`, `SignupClosed` and `Running` — never from `Finished`: a
 * tournament that was played to its end is history, and cancelling it would
 * throw away standings people hold.
 *
 * The page stays visible and marked rather than disappearing; the entrants
 * and the drawn bracket stay too. Agenda strikes the block from the
 * published programme when it hears this (timetable design §4).
 */
final class CancelCompetition
{
    use TransitionsCompetitionStatus;

    public function handle(Competition $competition): Competition
    {
        return DB::transaction(function () use ($competition): Competition {
            $competition = $this->transition($competition, CompetitionStatus::Cancelled);

            DB::afterCommit(static function () use ($competition): void {
                event(new CompetitionCancelled(
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

<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\GuardsCompetitionLifecycle;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Stage;

/**
 * The desk's "schedule all at" for one round (D-CMP-21, design §9).
 *
 * Scheduling in this slice is `competitions.starts_at` plus a desk-set
 * `scheduled_at` per match, and a LAN plays its rounds in waves — "round two
 * at 15:00" — so the per-round action is the one the desk uses most and the
 * per-match one is for the exception. The board and the duration estimator
 * that would stagger matches across stations stay #117; every match of the
 * round gets the **same** instant.
 *
 * It is `ScheduleMatch` once per match, not a bulk update, because each match
 * owes its own `scheduled`/`rescheduled` history row and its own
 * `MatchScheduled` for GameOps — a round of eight is eight pieces of news to
 * eight pairs of teams.
 *
 * A round is `(bracket, round)`: double elimination numbers the winners' and
 * losers' rounds independently, so "round 2" alone would be two different
 * waves. Only `pending` and `scheduled` matches move: a match being played
 * or already decided keeps the time it was played at, and one that already
 * sits at exactly this instant is left alone rather than given a
 * `rescheduled` row that says nothing changed.
 */
final class ScheduleRound
{
    use GuardsCompetitionLifecycle;

    public function __construct(
        private readonly ScheduleMatch $schedule,
    ) {}

    /**
     * @return int how many matches were given the time
     */
    public function handle(
        Stage $stage,
        ?string $bracket,
        int $round,
        CarbonInterface $at,
        ?string $scheduledByUserId = null,
    ): int {
        return DB::transaction(function () use ($stage, $bracket, $round, $at, $scheduledByUserId): int {
            // The competition lock first, so the round is read after every
            // writer before it has committed: a match finished a moment ago
            // is no longer `pending` here, and is left alone.
            $this->guardStillBeingPlayed($stage->competition_id);

            $matches = CompetitionMatch::withoutBrandScope()
                ->where('brand_id', $stage->brand_id)
                ->where('stage_id', $stage->id)
                ->where('round', $round)
                ->when($bracket === null, fn ($query) => $query->whereNull('bracket'))
                ->when($bracket !== null, fn ($query) => $query->where('bracket', $bracket))
                ->whereIn('state', [MatchState::Pending->value, MatchState::Scheduled->value])
                ->orderBy('position')
                ->get();

            $moved = 0;

            foreach ($matches as $match) {
                if ($match->scheduled_at?->equalTo($at)) {
                    continue;
                }

                $this->schedule->handle($match, $at, scheduledByUserId: $scheduledByUserId);
                $moved++;
            }

            return $moved;
        });
    }
}

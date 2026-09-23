<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\TransitionsCompetitionStatus;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\StageFormat;
use Konventix\Competition\Events\CompetitionStarted;
use Konventix\Competition\Exceptions\IllegalCompetitionStateTransitionException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\Stage;

/**
 * Starts the tournament: `SignupClosed → Running` (D-CMP-15, design §4).
 *
 * Two preconditions beyond the transition table, both from §4:
 *
 * - **A generated stage.** `Running` means "the first stage has been
 *   generated and started" — a competition whose bracket has not been drawn
 *   has nothing to record results against, and the desk would show an empty
 *   board. A **leaderboard** stage counts as ready without a draw: it has no
 *   matches (§7.2), `GenerateBracket` has no generator for it, and the desk
 *   offers it no draw button. Its field is whoever signed up, and scores
 *   are entered against that. Before this, a leaderboard competition could
 *   never reach `Running` and therefore never `Finished` (design §14,
 *   "A leaderboard needs no draw to start").
 * - **No `incomplete` entrant.** An incomplete roster holds a slot until the
 *   organizer fills it from the interested list or declines it (§5.4).
 *   Starting over one would either seed a team that cannot field itself or
 *   silently drop it — bracket generation never seeds one either.
 *
 * Both are refusals, not warnings: they are the two ways a tournament gets
 * started on a field that is not the field that will play it.
 */
final class StartCompetition
{
    use TransitionsCompetitionStatus;

    public function handle(Competition $competition): Competition
    {
        return DB::transaction(function () use ($competition): Competition {
            $this->guardGeneratedStage($competition);
            $this->guardCompleteRosters($competition);

            $competition = $this->transition($competition, CompetitionStatus::Running);

            DB::afterCommit(static function () use ($competition): void {
                event(new CompetitionStarted(
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

    /**
     * The **first** stage decides, because `Running` means the first stage
     * is drawn and started (§4). A leaderboard counts as ready undrawn; a
     * later leaderboard stage behind an undrawn bracket does not (review
     * fix round, M1).
     */
    private function guardGeneratedStage(Competition $competition): void
    {
        /** @var Stage|null $first */
        $first = Stage::withoutBrandScope()
            ->where('brand_id', $competition->brand_id)
            ->where('competition_id', $competition->id)
            ->orderBy('sequence')
            ->orderBy('id')
            ->first();

        $ready = $first !== null && ($first->generated_at !== null || $first->format === StageFormat::Leaderboard->value);

        if (! $ready) {
            throw IllegalCompetitionStateTransitionException::forPrecondition(
                $competition->id,
                'no stage has been generated yet — draw the bracket first.',
            );
        }
    }

    private function guardCompleteRosters(Competition $competition): void
    {
        $incomplete = Entrant::withoutBrandScope()
            ->where('brand_id', $competition->brand_id)
            ->where('competition_id', $competition->id)
            ->where('state', EntrantState::Incomplete->value)
            ->count();

        if ($incomplete > 0) {
            throw IllegalCompetitionStateTransitionException::forPrecondition(
                $competition->id,
                sprintf(
                    '%d roster(s) are still incomplete — fill them from the interested list or decline them.',
                    $incomplete,
                ),
            );
        }
    }
}

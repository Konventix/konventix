<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Events\MatchScheduled;
use Konventix\Competition\Exceptions\IllegalMatchStateTransitionException;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\MatchSlot;

/**
 * Gives a match a time, and tells GameOps about it (ADR-0012).
 *
 * `MatchScheduled` is the trigger of GameOps' whole provisioning flow, so it
 * is emitted only once the match actually has both a time and its sides — a
 * server provisioned for a match with an empty slot is a server nobody can
 * use.
 *
 * The game reference on the payload comes from the discipline and is null for
 * anything that is not a video game (ADR-0024). GameOps hearing a scheduled
 * quiz simply finds nothing to provision, which is the plugin-shaped
 * behaviour the ADR is after.
 */
final class ScheduleMatch
{
    /**
     * @var list<MatchState>
     */
    private const array LEGAL_FROM_STATES = [MatchState::Pending, MatchState::Scheduled];

    public function handle(CompetitionMatch $match, CarbonInterface $at, ?string $gameMode = null): CompetitionMatch
    {
        return DB::transaction(function () use ($match, $at, $gameMode): CompetitionMatch {
            $this->guardTransition($match);

            $competition = $match->competition()->withoutGlobalScopes()->firstOrFail();
            $discipline = $competition->discipline()->withoutGlobalScopes()->firstOrFail();

            $match->forceFill(['state' => MatchState::Scheduled, 'scheduled_at' => $at])->save();

            $entrantIds = MatchSlot::withoutBrandScope()
                ->where('competition_match_id', $match->id)
                ->whereNotNull('entrant_id')
                ->orderBy('position')
                ->pluck('entrant_id')
                ->all();

            event(new MatchScheduled(
                matchId: $match->id,
                competitionId: $competition->id,
                eventId: $competition->event_id,
                brandId: $match->brand_id,
                // Null for every discipline that is not a video game.
                gameId: $discipline->kind->isAutomatable() ? $discipline->game_id : null,
                gameMode: $discipline->kind->isAutomatable() ? $gameMode : null,
                round: $match->round,
                scheduledAt: $at->toIso8601String(),
                entrantIds: array_values(array_map(strval(...), $entrantIds)),
            ));

            return $match->refresh();
        });
    }

    private function guardTransition(CompetitionMatch $match): void
    {
        if (! in_array($match->state, self::LEGAL_FROM_STATES, true)) {
            throw IllegalMatchStateTransitionException::forTransition($match, MatchState::Scheduled);
        }
    }
}

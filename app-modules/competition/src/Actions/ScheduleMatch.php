<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\GuardsCompetitionLifecycle;
use Konventix\Competition\Data\MatchHistoryPayload;
use Konventix\Competition\Enums\MatchHistoryKind;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Events\MatchScheduled;
use Konventix\Competition\Exceptions\IllegalMatchStateTransitionException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchSlot;

/**
 * Gives a match a time, and tells GameOps about it (ADR-0012).
 *
 * `MatchScheduled` is the trigger of GameOps' whole provisioning flow, so it
 * is emitted only once the match actually has both a time and its sides — a
 * server provisioned for a match with an empty slot is a server nobody can
 * use. The desk's per-round "schedule all at" times a round before the
 * previous one is decided, which is the normal case from round two on, so a
 * match is often timed while a slot still waits for its side. Such a match
 * gets its time and its history row here and **no event**. `AdvanceBracket`
 * announces it when the last side arrives (`announce()`, design §14, "§12
 * reconciled"). A bye, whose empty slot nothing will ever fill, is never
 * announced, because there is nothing to play.
 *
 * The event means "this match is on, at this time, between these sides". It
 * is emitted again whenever one of the three changes while the other two are
 * known: a reschedule, and a correction that re-routes a side (§8.5).
 * Consumers treat it as an upsert keyed on `matchId`.
 *
 * The game reference on the payload comes from the discipline and is null for
 * anything that is not a video game (ADR-0024). GameOps hearing a scheduled
 * quiz simply finds nothing to provision, which is the plugin-shaped
 * behaviour the ADR is after.
 *
 * The rosters travel with it (#297): a server is configured with players, and
 * GameOps resolving entrant ids back into people would mean reading this
 * module, which is the direction the dependency must never run.
 *
 * Two of §8.6's history kinds are written here: `scheduled` the first time a
 * match is given a time, `rescheduled` when an existing one moves. They are
 * different rows because they are different news to the people playing — "you
 * are on at 14:00" and "you are no longer on at 14:00" — and the timeline is
 * shown to the sides, not only to the desk.
 */
final class ScheduleMatch
{
    use GuardsCompetitionLifecycle;

    /**
     * @var list<MatchState>
     */
    private const array LEGAL_FROM_STATES = [MatchState::Pending, MatchState::Scheduled];

    public function __construct(
        private readonly RecordMatchHistory $history,
    ) {}

    public function handle(
        CompetitionMatch $match,
        CarbonInterface $at,
        ?string $gameMode = null,
        ?string $scheduledByUserId = null,
    ): CompetitionMatch {
        return DB::transaction(function () use ($match, $at, $gameMode, $scheduledByUserId): CompetitionMatch {
            // Re-read under the lock: a match another desk lane finished a
            // moment ago must not flip back to `scheduled`.
            $this->lockMatchForPlay($match);
            $this->guardTransition($match);

            $competition = $match->competition()->withoutGlobalScopes()->firstOrFail();
            $discipline = $competition->discipline()->withoutGlobalScopes()->firstOrFail();

            $previouslyAt = $match->scheduled_at;

            $match->forceFill(['state' => MatchState::Scheduled, 'scheduled_at' => $at])->save();

            $this->history->handle(
                $match,
                $previouslyAt === null ? MatchHistoryKind::Scheduled : MatchHistoryKind::Rescheduled,
                new MatchHistoryPayload(
                    scheduledAt: $at->toIso8601String(),
                    previousScheduledAt: $previouslyAt?->toIso8601String(),
                ),
                $scheduledByUserId,
            );

            $this->announceWith($match, $competition, $discipline, $at, $gameMode);

            return $match->refresh();
        });
    }

    /**
     * Emits `MatchScheduled` for a match that has a time, once every one of
     * its slots holds a side; otherwise does nothing.
     *
     * Called by `handle()` and by `AdvanceBracket` when it fills a slot of a
     * match the desk has already timed. The dispatch is deferred to the
     * commit (`.ai/rules/app-modules-src.md`), so a refusal later in the
     * caller's transaction takes the announcement back with it.
     */
    public function announce(CompetitionMatch $match, ?string $gameMode = null): void
    {
        if ($match->state !== MatchState::Scheduled || $match->scheduled_at === null) {
            return;
        }

        $competition = $match->competition()->withoutGlobalScopes()->firstOrFail();
        $discipline = $competition->discipline()->withoutGlobalScopes()->firstOrFail();

        $this->announceWith($match, $competition, $discipline, $match->scheduled_at, $gameMode);
    }

    private function announceWith(
        CompetitionMatch $match,
        Competition $competition,
        Discipline $discipline,
        CarbonInterface $at,
        ?string $gameMode,
    ): void {
        $slots = MatchSlot::withoutBrandScope()
            ->where('competition_match_id', $match->id)
            ->orderBy('position')
            ->get(['entrant_id']);

        // A side still to come, or a bye: nothing GameOps could provision.
        if ($slots->isEmpty() || $slots->contains(fn (MatchSlot $slot): bool => $slot->entrant_id === null)) {
            return;
        }

        $entrantIds = array_values(array_map(strval(...), $slots->pluck('entrant_id')->all()));

        $scheduled = new MatchScheduled(
            matchId: $match->id,
            competitionId: $competition->id,
            eventId: $competition->event_id,
            brandId: $match->brand_id,
            // Null for every discipline that is not a video game.
            gameId: $discipline->kind->isAutomatable() ? $discipline->game_id : null,
            gameMode: $discipline->kind->isAutomatable() ? $gameMode : null,
            round: $match->round,
            scheduledAt: $at->toIso8601String(),
            entrantIds: $entrantIds,
            memberUserIds: $this->rostersFor($entrantIds),
        );

        DB::afterCommit(static function () use ($scheduled): void {
            event($scheduled);
        });
    }

    /**
     * The roster behind each side, keyed by entrant ULID. `withoutBrandScope()`
     * because a schedule may be written from a queue worker or the admin host,
     * where the scope reads zero rows (ADR-0029).
     *
     * @param  list<mixed>  $entrantIds
     * @return array<string, list<string>>
     */
    private function rostersFor(array $entrantIds): array
    {
        $rosters = [];

        foreach ($entrantIds as $entrantId) {
            /** @var Entrant|null $entrant */
            $entrant = Entrant::withoutBrandScope()->whereKey((string) $entrantId)->first();

            if ($entrant !== null) {
                $rosters[(string) $entrantId] = $entrant->memberUserIds();
            }
        }

        return $rosters;
    }

    private function guardTransition(CompetitionMatch $match): void
    {
        if (! in_array($match->state, self::LEGAL_FROM_STATES, true)) {
            throw IllegalMatchStateTransitionException::forTransition($match, MatchState::Scheduled);
        }
    }
}

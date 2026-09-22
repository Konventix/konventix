<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Entrant;

/**
 * Moves a team's entries between `incomplete` and `registered` as its roster
 * changes (design §5.4).
 *
 * The state is derived, never set by hand: `EnterCompetition` creates the
 * entrant `registered` when the roster already equals the discipline's
 * `team_size` and `incomplete` otherwise, and every join or leave afterwards
 * comes back through here. A caller that had to remember to update the state
 * would eventually forget, and the symptom — a three-player team seeded into
 * a 5v5 bracket — surfaces at the desk on the day.
 *
 * **Complete means exactly the team size, not "at least".** `>=` was the
 * earlier comparison and it was wrong in a way that only showed up across two
 * competitions: a five-person squad entered in both a 5v5 and a 1v1 read as
 * *complete* for the 1v1 too, and seeded five member ULIDs behind a
 * one-player side. Both doors onto a roster now refuse an oversized one by
 * name — `EnterCompetition` for the entry, `MaintainsTeamRoster` for the
 * join — so this branch cannot see one any more. If it somehow did, landing
 * in §5.4's prompt where an organizer can decline it is strictly better than
 * seeding it, which is the other reason the comparison is exact.
 *
 * Two deliberate limits beyond that:
 *
 * - **Only `incomplete` ↔ `registered`.** A `checked_in` entrant is left
 *   alone; check-in is §6's business and a roster change after it is a
 *   question for the desk, not something a projection should silently undo.
 *   `withdrawn` and `disqualified` are terminal.
 * - **Only before the bracket runs.** A competition in `Running`, `Finished`
 *   or `Cancelled` has a drawn field; flipping a seeded entrant to
 *   `incomplete` because the organizer moved a player would unseed a team
 *   mid-tournament. `start` already refuses while anything is `incomplete`,
 *   so nothing incomplete can be in there to begin with.
 */
final class SyncEntrantCompleteness
{
    /**
     * @return int how many entries changed state
     */
    public function handle(CompetitionTeam $team): int
    {
        return DB::transaction(function () use ($team): int {
            $rosterSize = count($team->memberUserIds());

            /** @var Collection<int, Entrant> $entrants */
            $entrants = Entrant::withoutBrandScope()
                ->where('brand_id', $team->brand_id)
                ->where('competition_team_id', $team->getKey())
                ->whereIn('state', [EntrantState::Incomplete->value, EntrantState::Registered->value])
                ->get();

            $changed = 0;

            foreach ($entrants as $entrant) {
                /** @var Competition|null $competition */
                $competition = Competition::withoutBrandScope()->whereKey($entrant->competition_id)->first();

                if ($competition === null || ! $this->fieldIsStillForming($competition->status)) {
                    continue;
                }

                $target = $rosterSize === $this->teamSizeFor($competition)
                    ? EntrantState::Registered
                    : EntrantState::Incomplete;

                if ($entrant->state === $target) {
                    continue;
                }

                $entrant->forceFill(['state' => $target])->save();
                $changed++;
            }

            return $changed;
        });
    }

    /**
     * What a full roster is for this competition — the discipline's own
     * `team_size`, held locally rather than read from GameOps (ADR-0024).
     * A discipline that has vanished cannot make anything incomplete, so it
     * reads as a team of one.
     */
    private function teamSizeFor(Competition $competition): int
    {
        /** @var Discipline|null $discipline */
        $discipline = Discipline::withoutBrandScope()->whereKey($competition->discipline_id)->first();

        return max(1, $discipline->team_size ?? 1);
    }

    private function fieldIsStillForming(CompetitionStatus $status): bool
    {
        return in_array($status, [
            CompetitionStatus::Draft,
            CompetitionStatus::Published,
            CompetitionStatus::SignupClosed,
        ], true);
    }
}

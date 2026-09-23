<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\MaintainsTeamRoster;
use Konventix\Competition\Actions\Concerns\NotifiesRosterMembers;
use Konventix\Competition\Data\EntrantNoticeData;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Entrant;

/**
 * One of the two answers to §5.4's prompt: the organizer fills a short roster
 * up from the interested list (or from any eligible person) and the entry
 * becomes `registered`.
 *
 * This is the reason the interested list exists and the reason an incomplete
 * entrant holds a slot: three players who put their name down for a 5v5 and
 * two more who wrote "would play, no team" are a team, and the only thing
 * missing was somebody to introduce them.
 *
 * The chosen people are notified through Messaging, because being put on a
 * stranger's roster is news you should not discover at the desk.
 *
 * The state is **not** written here. Each added member goes through
 * `MaintainsTeamRoster`, which calls `SyncEntrantCompleteness`; when the
 * roster reaches the discipline's `team_size` the entry moves to `registered`
 * by itself. One place decides what "complete" means, and it is not this
 * Action.
 *
 * No second `EntrantRegistered`. The entry was announced when it was made —
 * with `state: incomplete` — and announcing it again under the same entrant
 * id would have consumers registering one team twice. The roster's final
 * shape reaches GameOps where it is actually needed, on `MatchScheduled`.
 */
final class FillIncompleteEntrant
{
    use MaintainsTeamRoster;
    use NotifiesRosterMembers;

    /**
     * @param  list<string>  $userIds  the people the organizer picked, in order
     */
    public function handle(Entrant $entrant, array $userIds): Entrant
    {
        return DB::transaction(function () use ($entrant, $userIds): Entrant {
            if ($entrant->state !== EntrantState::Incomplete) {
                throw CompetitionEntryException::entrantNotIncomplete((string) $entrant->getKey());
            }

            /** @var Competition $competition */
            $competition = Competition::withoutBrandScope()->whereKey($entrant->competition_id)->firstOrFail();

            /** @var CompetitionTeam $team */
            $team = CompetitionTeam::withoutBrandScope()->whereKey($entrant->competition_team_id)->firstOrFail();

            foreach ($userIds as $userId) {
                $this->addRosterMember($team, $userId);

                // The person joined a roster, so they are no longer looking.
                // `addRosterMember()` already retires interest for every
                // competition the team holds a slot in, this one included.
            }

            // After the commit and only where the brand has the module on,
            // like every other roster notice (final review, part 1).
            $this->notifyRosterAfterCommit(
                $userIds,
                $competition->brand_id,
                'competition.roster-filled',
                new EntrantNoticeData(
                    competitionName: $competition->name,
                    teamName: $team->name,
                    detail: __('Die Orga hat dich für dieses Turnier ins Team aufgenommen.'),
                ),
            );

            return $entrant->refresh();
        });
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\MaintainsTeamRoster;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\CompetitionTeamMember;

/**
 * An organizer puts somebody on a team by user search (design §5.2,
 * `ManageEntrants`) — the desk's version of the invite code, for the player
 * standing in front of them whose captain has already gone to get food.
 *
 * Every rule the self-service join obeys still applies: the §5.1 gate, the
 * roster bound, the interested-list retirement and the completeness
 * recompute, all of them in `MaintainsTeamRoster`. An organizer power is a
 * shortcut past the *code*, not past the eligibility.
 */
final class AddTeamMember
{
    use MaintainsTeamRoster;

    public function handle(CompetitionTeam $team, string $userId): CompetitionTeamMember
    {
        return DB::transaction(fn (): CompetitionTeamMember => $this->addRosterMember($team, $userId));
    }
}

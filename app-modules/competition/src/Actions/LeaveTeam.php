<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\MaintainsTeamRoster;
use Konventix\Competition\Models\CompetitionTeam;

/**
 * A player takes themselves off a roster (design §5.2, §10's `/my/tournaments`).
 *
 * Captaincy rotates to the longest-standing remaining member if the person
 * leaving held it — LanCore's rule, kept, because a team whose captain walked
 * off and left nobody able to regenerate the code or withdraw is a support
 * ticket every single time.
 *
 * Leaving may make an entry `incomplete`; that happens by itself in
 * `SyncEntrantCompleteness`, which is what the roster concern calls. The
 * entry is **not** withdrawn: four of five players still wanting to play is
 * exactly the case §5.4's fill-up exists for, and dropping them because one
 * person left would be the platform making the organizer's decision.
 */
final class LeaveTeam
{
    use MaintainsTeamRoster;

    public function handle(CompetitionTeam $team, string $userId): void
    {
        DB::transaction(function () use ($team, $userId): void {
            $this->removeRosterMember($team, $userId);
        });
    }
}

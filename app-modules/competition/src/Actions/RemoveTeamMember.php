<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\MaintainsTeamRoster;
use Konventix\Competition\Models\CompetitionTeam;

/**
 * An organizer takes somebody off a team (design §5.2, `ManageEntrants`).
 *
 * Captaincy rotates if the removed person held it, and the team's entries are
 * rechecked for completeness — a five-player roster that loses one is
 * `incomplete` again and lands back in §5.4's prompt, which is exactly where
 * the organizer wants it.
 */
final class RemoveTeamMember
{
    use MaintainsTeamRoster;

    public function handle(CompetitionTeam $team, string $userId): void
    {
        DB::transaction(function () use ($team, $userId): void {
            $this->removeRosterMember($team, $userId);
        });
    }
}

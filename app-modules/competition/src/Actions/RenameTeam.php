<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Konventix\Competition\Models\CompetitionTeam;

/**
 * An organizer renames a team (design §5.2, `ManageEntrants`) — the desk's
 * remedy for the name that cannot go on a stream overlay.
 *
 * Names are unique per brand and event (`competition_teams_brand_id_event_id_name_unique`),
 * and the clash comes back as a field error rather than a 500: the organizer
 * typed a name another team at this event already holds, which is something
 * to be told in the form they are standing in.
 */
final class RenameTeam
{
    public function handle(CompetitionTeam $team, string $name): CompetitionTeam
    {
        try {
            $team->forceFill(['name' => $name])->save();
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'competition_teams_brand_id_event_id_name_unique')) {
                throw ValidationException::withMessages([
                    'name' => __('Another team at this event already uses that name.'),
                ]);
            }

            throw $e;
        }

        return $team;
    }
}

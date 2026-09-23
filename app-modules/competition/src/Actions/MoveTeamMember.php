<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\MaintainsTeamRoster;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\CompetitionTeamMember;

/**
 * An organizer moves a player from one team to another (design §5.2,
 * `ManageEntrants`) — the "I signed up with the wrong squad" fix, which at a
 * LAN happens within the first hour, every time.
 *
 * One transaction, and the departure happens first so the destination's
 * roster bound is measured without this person counted on both sides. If the
 * arrival then fails — the destination is full, or the player holds no
 * confirmed registration and no ticket in their name (§5.1 addendum) — the whole move rolls back and they are still on
 * the team they started on. Nobody ends up belonging to nobody.
 *
 * Both teams are rechecked for completeness on the way through, so the team
 * losing a player lands back in §5.4's prompt and the one gaining them may
 * leave it.
 *
 * Both teams must be at the same event — a team is per event (D-CMP-14), so
 * a move across events is not a move, it is two different tournaments.
 */
final class MoveTeamMember
{
    use MaintainsTeamRoster;

    public function handle(CompetitionTeam $from, CompetitionTeam $to, string $userId): CompetitionTeamMember
    {
        return DB::transaction(function () use ($from, $to, $userId): CompetitionTeamMember {
            if (! $from->hasMember($userId)) {
                throw CompetitionEntryException::notOnTeam($userId, (string) $from->getKey());
            }

            if ($from->event_id !== $to->event_id) {
                throw CompetitionEntryException::teamsAtDifferentEvents(
                    (string) $from->getKey(),
                    (string) $to->getKey(),
                );
            }

            $this->removeRosterMember($from, $userId);

            return $this->addRosterMember($to, $userId);
        });
    }
}

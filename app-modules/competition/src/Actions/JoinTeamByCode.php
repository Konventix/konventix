<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\MaintainsTeamRoster;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\CompetitionTeamMember;

/**
 * The only self-service way onto a roster (design §5.2): somebody types the
 * eight-character code their captain posted on Discord.
 *
 * There is deliberately no open join and no request-to-join queue. A code is
 * the smaller mechanism, it is the one LanCore's organizers actually used,
 * and when it leaks the captain regenerates it — which is a thing a captain
 * can do at two in the morning without the desk.
 *
 * The gate is §5.1's and nothing else: a confirmed registration for the
 * competition's event, or since #330 a non-revoked admission in one's own
 * name (§5.1 addendum). It is enforced in `MaintainsTeamRoster::addRosterMember()`
 * along with the roster bound, because joining a team that is already entered
 * puts you in the bracket exactly as entering would have.
 *
 * The code is looked up **within the brand**, which is where it is unique.
 * Two brands may hand out the same eight characters and neither is wrong.
 */
final class JoinTeamByCode
{
    use MaintainsTeamRoster;

    public function handle(string $brandId, string $inviteCode, string $userId): CompetitionTeamMember
    {
        return DB::transaction(function () use ($brandId, $inviteCode, $userId): CompetitionTeamMember {
            $normalized = CompetitionTeam::normalizeInviteCode($inviteCode);

            /** @var CompetitionTeam|null $team */
            $team = CompetitionTeam::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('invite_code', $normalized)
                ->lockForUpdate()
                ->first();

            if ($team === null) {
                throw CompetitionEntryException::unknownInviteCode($normalized);
            }

            return $this->addRosterMember($team, $userId);
        });
    }
}

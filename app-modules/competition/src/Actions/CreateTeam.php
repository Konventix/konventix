<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Competition\Actions\Concerns\MaintainsTeamRoster;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\EligibleRegistration;

/**
 * Somebody starts a team for an event (design §5.2).
 *
 * The person creating it **is** its captain — that is what "assigned at
 * creation" means, and it is why a solo entrant is a team of one whose only
 * member is captain (D-CMP-13). The invite code is issued here, because a
 * team without one is a team nobody else can reach.
 *
 * The §5.1 gate applies: a confirmed registration for the event. A team
 * founded by somebody who is not coming is a roster the desk has to unpick.
 *
 * The name clash comes back as a field error — names are unique per brand and
 * event, and "that name is taken" belongs in the form, not in a 500.
 */
final class CreateTeam
{
    use MaintainsTeamRoster;

    private const int MAX_ATTEMPTS = 10;

    public function handle(string $brandId, string $eventId, string $name, string $captainUserId): CompetitionTeam
    {
        return DB::transaction(function () use ($brandId, $eventId, $name, $captainUserId): CompetitionTeam {
            if (! EligibleRegistration::userMayEnter($brandId, $eventId, $captainUserId)) {
                throw CompetitionEntryException::noConfirmedRegistration($captainUserId, $eventId);
            }

            $team = new CompetitionTeam(['event_id' => $eventId, 'name' => $name]);
            // brand_id stated, never mass-assigned and never ambient (ADR-0010).
            $team->forceFill(['brand_id' => $brandId]);

            $this->saveWithFreshCode($team);

            $this->addRosterMember($team, $captainUserId);

            return $team;
        });
    }

    /**
     * Uniqueness is the index's job. A code collision is retried; a name
     * collision is the organizer's — or the player's — to resolve.
     */
    private function saveWithFreshCode(CompetitionTeam $team): void
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $team->forceFill(['invite_code' => CompetitionTeam::generateInviteCode()]);

            try {
                $team->save();

                return;
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'competition_teams_brand_id_event_id_name_unique')) {
                    throw ValidationException::withMessages([
                        'name' => __('Diesen Teamnamen gibt es bei dieser Veranstaltung schon.'),
                    ]);
                }

                if (! str_contains($e->getMessage(), 'invite_code')) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Could not find a free invite code in '.self::MAX_ATTEMPTS.' attempts.');
    }
}

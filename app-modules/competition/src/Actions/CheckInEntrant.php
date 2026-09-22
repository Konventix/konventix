<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Events\EntrantCheckedIn;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\AdmissionPresence;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\ParticipantProfile;

/**
 * A team says it is here (design §6, D-CMP-18).
 *
 * Two callers, one Action, because the *fact* recorded is the same and only
 * the permission to record it differs:
 *
 * - **The desk**, behind `CheckInEntrants`. It may check a team in whatever
 *   the mode — §6 is explicit about that — so nothing here asks the mode on
 *   this path. A desk that cannot correct what a captain got wrong is not a
 *   desk.
 * - **The captain**, by passing their own user id. That path additionally
 *   requires `check_in_mode = self_service`, an open window
 *   (`Competition::selfServiceCheckInIsOpen()`) and that the person really
 *   captains the team. Those three live here rather than in the controller
 *   because they are the rule, not a rendering detail.
 *
 * **The door requirement is answered from this module's own read model.**
 * With `requires_event_check_in`, every roster member must have been scanned
 * at the event entrance; the fact comes from `competition_admission_presence`
 * (projected from Admission's public events, §6) and never from a query into
 * Admission, which ADR-0004 forbids.
 *
 * Checking in an already checked-in entrant is a no-op rather than a
 * refusal: a desk clicking twice, or a captain hitting the button on two
 * devices, is not an error and must not fire a second contract event.
 */
final class CheckInEntrant
{
    /**
     * @param  string|null  $selfServiceUserId  the captain checking their own team in; null is the desk
     */
    public function handle(Entrant $entrant, ?string $selfServiceUserId = null): Entrant
    {
        return DB::transaction(function () use ($entrant, $selfServiceUserId): Entrant {
            /** @var Competition|null $competition */
            $competition = Competition::withoutBrandScope()->whereKey($entrant->competition_id)->first();

            if ($competition === null) {
                throw CompetitionEntryException::competitionOver($entrant->competition_id);
            }

            if (in_array($competition->status, [
                CompetitionStatus::Finished,
                CompetitionStatus::Cancelled,
            ], true)) {
                throw CompetitionEntryException::competitionOver((string) $competition->getKey());
            }

            // The state is re-read under a row lock: two desk lanes clicking
            // the same team at once would otherwise both pass the check below
            // and both fire the contract event. Task 1's status writer locks
            // for the same reason.
            /** @var Entrant $locked */
            $locked = Entrant::withoutBrandScope()->whereKey($entrant->getKey())->lockForUpdate()->firstOrFail();
            $entrant->setRawAttributes($locked->getAttributes(), true);

            // Idempotent before every other guard: a second click must not
            // fail on a window that has meanwhile closed.
            if ($entrant->state === EntrantState::CheckedIn) {
                return $entrant;
            }

            if ($entrant->state !== EntrantState::Registered) {
                throw CompetitionEntryException::entrantNotCheckable(
                    (string) $entrant->getKey(),
                    $entrant->state->value,
                );
            }

            /** @var CompetitionTeam|null $team */
            $team = CompetitionTeam::withoutBrandScope()->whereKey($entrant->competition_team_id)->first();
            $roster = $team?->memberUserIds() ?? [];

            if ($roster === []) {
                throw CompetitionEntryException::emptyRoster($entrant->competition_team_id);
            }

            if ($selfServiceUserId !== null) {
                $this->guardSelfService($competition, $team, $entrant->competition_team_id, $selfServiceUserId);
            }

            if ($competition->requires_event_check_in) {
                $this->guardTheDoor($competition, $entrant, $roster);
            }

            $checkedInAt = now();

            $entrant->forceFill([
                'state' => EntrantState::CheckedIn,
                'checked_in_at' => $checkedInAt,
            ])->save();

            $checkedIn = new EntrantCheckedIn(
                entrantId: (string) $entrant->getKey(),
                competitionId: $entrant->competition_id,
                competitionTeamId: $entrant->competition_team_id,
                eventId: $competition->event_id,
                brandId: $competition->brand_id,
                memberUserIds: $roster,
                checkedInAt: $checkedInAt->toIso8601String(),
                viaSelfService: $selfServiceUserId !== null,
            );

            DB::afterCommit(static function () use ($checkedIn): void {
                event($checkedIn);
            });

            return $entrant->refresh();
        });
    }

    /**
     * The captain's path. Membership alone is not enough — §6 says "the
     * captain checks the team in", and a roster member who has not arrived
     * yet should not be able to declare the rest of the team present.
     */
    private function guardSelfService(
        Competition $competition,
        ?CompetitionTeam $team,
        string $teamId,
        string $userId,
    ): void {
        if (! $competition->selfServiceCheckInIsOpen()) {
            throw CompetitionEntryException::selfServiceCheckInClosed((string) $competition->getKey());
        }

        if ($team === null || ! $team->isCaptain($userId)) {
            throw CompetitionEntryException::notTheCaptain($userId, $teamId);
        }
    }

    /**
     * `requires_event_check_in` (design §6): every roster member has to have
     * been through the door. Read from `competition_admission_presence` —
     * Competition's own projection of Admission's public events — and never
     * from Admission's tables (ADR-0004).
     *
     * The refusal names who is missing — by **display name**, from this
     * module's own `ParticipantProfile` read model — because "somebody is not
     * here" is useless at a desk with a queue behind it, and a ULID is no
     * better: nobody at the table can turn one into a person. A member with
     * no profile row yet falls back to a dash, which is the projection's own
     * rule and still counts towards the number.
     *
     * @param  list<string>  $roster
     */
    private function guardTheDoor(Competition $competition, Entrant $entrant, array $roster): void
    {
        $missing = AdmissionPresence::missingAtTheDoor(
            $competition->brand_id,
            $competition->event_id,
            $roster,
        );

        if ($missing !== []) {
            throw CompetitionEntryException::rosterNotAtTheDoor(
                (string) $entrant->getKey(),
                array_values(ParticipantProfile::displayNamesFor($missing)),
            );
        }
    }
}

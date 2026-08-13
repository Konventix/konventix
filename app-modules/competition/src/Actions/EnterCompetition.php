<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Events\EntrantRegistered;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\EligibleRegistration;
use Konventix\Competition\Models\Entrant;

/**
 * Enters a team in a competition (D-CMP-05, D-CMP-13, D-CMP-14).
 *
 * **The entry gate is a confirmed Registration, and nothing else.** D-CMP-05
 * settles that admission possession is verified later, at check-in or match
 * start — so this Action must not reach for an admission, and does not. It
 * asks Competition's own eligibility read model, built from Registration's
 * contract events, never Registration's tables (ADR-0004).
 *
 * Every entrant is a team, including a solo player (D-CMP-13): one shape
 * means the bracket engine never branches on whether a side is a person or a
 * squad.
 *
 * Double entry is stopped by a partial unique index rather than a check —
 * a double-tapped signup button is exactly the race a read-then-write loses,
 * and a bracket containing the same team twice is a tournament nobody can
 * run.
 */
final class EnterCompetition
{
    /**
     * @param  list<string>  $memberUserIds  every member must hold a confirmed registration
     */
    public function handle(Competition $competition, CompetitionTeam $team, array $memberUserIds): Entrant
    {
        return DB::transaction(function () use ($competition, $team, $memberUserIds): Entrant {
            if (! $competition->signupIsOpen()) {
                throw CompetitionEntryException::signupClosed($competition->id);
            }

            // Teams are per event (D-CMP-14); one from last year's event has
            // no business in this year's bracket.
            if ($team->event_id !== $competition->event_id) {
                throw CompetitionEntryException::wrongEvent($competition->id, $team->id);
            }

            foreach ($memberUserIds as $userId) {
                if (! EligibleRegistration::userMayEnter($competition->brand_id, $competition->event_id, $userId)) {
                    throw CompetitionEntryException::noConfirmedRegistration($userId, $competition->event_id);
                }
            }

            $entrant = new Entrant([
                'competition_id' => $competition->id,
                'competition_team_id' => $team->id,
                'state' => EntrantState::Registered,
            ]);
            // brand_id from the competition, not ambient context (ADR-0010).
            $entrant->forceFill(['brand_id' => $competition->brand_id]);

            try {
                $entrant->save();
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'entrants_one_live_per_team')) {
                    throw CompetitionEntryException::alreadyEntered($competition->id, $team->id);
                }

                throw $e;
            }

            event(new EntrantRegistered(
                entrantId: $entrant->id,
                competitionId: $competition->id,
                competitionTeamId: $team->id,
                eventId: $competition->event_id,
                brandId: $competition->brand_id,
                registeredAt: now()->toIso8601String(),
            ));

            return $entrant;
        });
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\MaintainsTeamRoster;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Events\EntrantRegistered;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionInterest;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\EligibleRegistration;
use Konventix\Competition\Models\Entrant;

/**
 * Enters a team in a competition (D-CMP-05, D-CMP-13, D-CMP-14, design §5).
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
 * **A short roster still enters** (§5.4). The entry is created `incomplete`
 * rather than refused: three players who want to run a 5v5 are exactly the
 * people the interested list exists for, and an entry that held no slot would
 * leave the organizer's fill-up at signup close with nothing to fill. `start`
 * refuses while any entrant is still `incomplete`, so the state cannot leak
 * into a bracket.
 *
 * **A roster too large is refused outright**, which is not the same question.
 * Being short is a state the design has a whole workflow for; being five
 * people in a one-player contest is not a state at all, and there are no
 * substitutes in this slice (§5.2). It is a named refusal rather than a
 * silent `registered`, because the silent version puts five member ULIDs
 * behind a side the bracket will only ever field one of.
 *
 * Double entry is stopped by a partial unique index rather than a check —
 * a double-tapped signup button is exactly the race a read-then-write loses,
 * and a bracket containing the same team twice is a tournament nobody can
 * run. The index covers `incomplete` too, or a team could enter twice while
 * short-handed and appear twice once it filled up.
 *
 * `max_entrants` is checked in the transaction rather than by a constraint:
 * it is a soft limit the organizer may raise, and overshooting it by one on a
 * simultaneous double signup is a thing to fix in the console, not a
 * tournament that cannot be run.
 */
final class EnterCompetition
{
    use MaintainsTeamRoster;

    /**
     * @param  list<string>  $memberUserIds  the roster entering; each must hold a confirmed
     *                                       registration, and anyone not yet on the team is
     *                                       put on it here. Empty means "whoever is already
     *                                       on the team".
     */
    public function handle(Competition $competition, CompetitionTeam $team, array $memberUserIds = []): Entrant
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

            $this->guardParticipantLimit($competition);

            foreach ($memberUserIds as $userId) {
                if (! EligibleRegistration::userMayEnter($competition->brand_id, $competition->event_id, $userId)) {
                    throw CompetitionEntryException::noConfirmedRegistration($userId, $competition->event_id);
                }

                if (! $team->hasMember($userId)) {
                    $this->addRosterMember($team, $userId);
                }
            }

            $roster = $team->memberUserIds();

            if ($roster === []) {
                throw CompetitionEntryException::emptyRoster((string) $team->getKey());
            }

            $teamSize = $this->teamSizeFor($competition);

            // A roster too *large* is refused by name rather than quietly
            // registered. `>=` alone would have read a five-person squad
            // entering a 1v1 as complete and seeded five people behind a
            // one-player side; there are no substitutes in this slice (§5.2).
            if (count($roster) > $teamSize) {
                throw CompetitionEntryException::rosterFull(
                    (string) $team->getKey(),
                    (string) $competition->getKey(),
                    $teamSize,
                    count($roster),
                );
            }

            $state = count($roster) === $teamSize
                ? EntrantState::Registered
                : EntrantState::Incomplete;

            $entrant = new Entrant([
                'competition_id' => $competition->id,
                'competition_team_id' => $team->id,
                'state' => $state,
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

            // Everyone now on this roster has stopped looking for a team
            // (§5.3). Done here rather than in the roster concern because
            // until this moment the team was entered in nothing.
            CompetitionInterest::withoutBrandScope()
                ->where('brand_id', $competition->brand_id)
                ->where('competition_id', $competition->id)
                ->whereIn('user_id', $roster)
                ->delete();

            event(new EntrantRegistered(
                entrantId: $entrant->id,
                competitionId: $competition->id,
                competitionTeamId: $team->id,
                eventId: $competition->event_id,
                brandId: $competition->brand_id,
                registeredAt: now()->toIso8601String(),
                memberUserIds: $roster,
                state: $state->value,
            ));

            return $entrant;
        });
    }

    /**
     * An incomplete entrant holds a slot against the limit, which is what
     * makes the fill-up at signup close possible at all (§5.4).
     */
    private function guardParticipantLimit(Competition $competition): void
    {
        if ($competition->max_entrants === null) {
            return;
        }

        $held = Entrant::countSlotHolders($competition->brand_id, (string) $competition->getKey());

        if ($held >= $competition->max_entrants) {
            throw CompetitionEntryException::competitionFull($competition->id, $competition->max_entrants);
        }
    }

    private function teamSizeFor(Competition $competition): int
    {
        /** @var Discipline|null $discipline */
        $discipline = Discipline::withoutBrandScope()->whereKey($competition->discipline_id)->first();

        return max(1, $discipline->team_size ?? 1);
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions\Concerns;

use Konventix\Competition\Actions\SyncEntrantCompleteness;
use Konventix\Competition\Enums\TeamRole;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionInterest;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\CompetitionTeamMember;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\EligibleRegistration;
use Konventix\Competition\Models\Entrant;

/**
 * The three things that must happen together whenever a roster changes
 * (design §5.2, §5.3, §5.4). Every Action that adds or removes a member goes
 * through here, so none of them can grow a private idea of what a join costs.
 *
 * 1. **The gate is a confirmed registration** (§5.1, D-CMP-05) — the same one
 *    `EnterCompetition` asks, because joining a team that is already entered
 *    puts you in the bracket exactly as entering would.
 * 2. **Interest is retired on joining.** Somebody on a roster is no longer
 *    looking for a team, and leaving their row on the public list is how two
 *    organizers end up recruiting the same person twice (§5.3).
 * 3. **Completeness is recomputed.** A join can complete a roster and a leave
 *    can break one; `SyncEntrantCompleteness` moves the entry between
 *    `incomplete` and `registered` so the state is never something a caller
 *    has to remember to set (§5.4).
 *
 * Captaincy is maintained here too: the first member of a team is its captain
 * and a departing captain hands over to the longest-standing member. Every
 * query escapes `BrandScope` explicitly — these Actions run from the brand
 * site, from the operator console where the scope hardens to zero rows
 * (ADR-0029), and from queue workers with no ambient brand.
 */
trait MaintainsTeamRoster
{
    /**
     * Puts a person on a roster. Refuses an ineligible person, a duplicate,
     * and a roster already at the discipline's team size.
     */
    protected function addRosterMember(CompetitionTeam $team, string $userId): CompetitionTeamMember
    {
        if (! EligibleRegistration::userMayEnter($team->brand_id, $team->event_id, $userId)) {
            throw CompetitionEntryException::noConfirmedRegistration($userId, $team->event_id);
        }

        if ($team->hasMember($userId)) {
            throw CompetitionEntryException::alreadyOnTeam($userId, (string) $team->getKey());
        }

        $bound = $this->rosterBound($team);

        if ($bound !== null && count($team->memberUserIds()) >= $bound) {
            throw CompetitionEntryException::rosterFull((string) $team->getKey(), $bound);
        }

        $member = new CompetitionTeamMember([
            'competition_team_id' => (string) $team->getKey(),
            'user_id' => $userId,
            // The first person on a team captains it (§5.2); a solo entrant
            // is a team of one whose only member is captain (D-CMP-13).
            'role' => $team->captain() === null ? TeamRole::Captain : TeamRole::Member,
        ]);
        // brand_id from the team, never ambient context (ADR-0010).
        $member->forceFill(['brand_id' => $team->brand_id]);
        $member->save();

        $this->retireInterest($team, $userId);
        $this->syncCompleteness($team);

        return $member;
    }

    /**
     * Takes a person off a roster, rotating captaincy to the longest-standing
     * remaining member if the person leaving held it. A team left with nobody
     * keeps no captain and is the organizer's problem, not a crash.
     */
    protected function removeRosterMember(CompetitionTeam $team, string $userId): void
    {
        /** @var CompetitionTeamMember|null $member */
        $member = CompetitionTeamMember::withoutBrandScope()
            ->where('competition_team_id', $team->getKey())
            ->where('user_id', $userId)
            ->first();

        if ($member === null) {
            throw CompetitionEntryException::notOnTeam($userId, (string) $team->getKey());
        }

        $wasCaptain = $member->isCaptain();

        $member->delete();

        if ($wasCaptain) {
            $this->rotateCaptaincy($team);
        }

        $this->syncCompleteness($team);
    }

    /**
     * The longest-standing remaining member takes over (§5.2, LanCore's
     * rule). Ordered by `created_at` then `id`, so two members written in the
     * same millisecond still resolve to one deterministic winner.
     */
    protected function rotateCaptaincy(CompetitionTeam $team): void
    {
        /** @var CompetitionTeamMember|null $successor */
        $successor = CompetitionTeamMember::withoutBrandScope()
            ->where('competition_team_id', $team->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        $successor?->forceFill(['role' => TeamRole::Captain])->save();
    }

    /**
     * How many people the roster may hold: the largest `team_size` among the
     * disciplines of the competitions this team currently holds a slot in.
     *
     * The largest, not the smallest, because a team entered in a 5v5 and a
     * 1v1 at the same event is a legitimate shape (§5.2: "a team is per event
     * and may enter several competitions") and the narrower contest is
     * bounded where it is enforced — `SyncEntrantCompleteness` marks that
     * entry `incomplete` rather than the roster refusing a sixth player.
     *
     * Null means unbounded: a team that has entered nothing yet has nothing
     * to be measured against, and refusing its second member would make the
     * usual order of operations — gather the squad, then enter — impossible.
     */
    protected function rosterBound(CompetitionTeam $team): ?int
    {
        $competitionIds = Entrant::withoutBrandScope()
            ->where('brand_id', $team->brand_id)
            ->where('competition_team_id', $team->getKey())
            ->whereIn('state', Entrant::slotHoldingStates())
            ->pluck('competition_id');

        if ($competitionIds->isEmpty()) {
            return null;
        }

        $disciplineIds = Competition::withoutBrandScope()
            ->whereIn('id', $competitionIds)
            ->pluck('discipline_id');

        $bound = Discipline::withoutBrandScope()
            ->whereIn('id', $disciplineIds)
            ->max('team_size');

        return $bound === null ? null : (int) $bound;
    }

    /**
     * Somebody who joined a roster is no longer on the interested list of any
     * competition that roster is entered in (§5.3).
     */
    protected function retireInterest(CompetitionTeam $team, string $userId): void
    {
        $competitionIds = Entrant::withoutBrandScope()
            ->where('brand_id', $team->brand_id)
            ->where('competition_team_id', $team->getKey())
            ->whereIn('state', Entrant::slotHoldingStates())
            ->pluck('competition_id');

        if ($competitionIds->isEmpty()) {
            return;
        }

        CompetitionInterest::withoutBrandScope()
            ->where('brand_id', $team->brand_id)
            ->whereIn('competition_id', $competitionIds)
            ->where('user_id', $userId)
            ->delete();
    }

    protected function syncCompleteness(CompetitionTeam $team): void
    {
        app(SyncEntrantCompleteness::class)->handle($team);
    }
}

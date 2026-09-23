<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions\Concerns;

use Konventix\Competition\Actions\SyncEntrantCompleteness;
use Konventix\Competition\Enums\LinkedProvider;
use Konventix\Competition\Enums\TeamRole;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionInterest;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\CompetitionTeamMember;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\EligibleRegistration;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\LinkedIdentity;

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
 * 4. **The linked-account rule holds** (§5.6). The person joining must have
 *    linked every provider that the disciplines of the team's current
 *    contests require. It is checked last, after the gate and the roster
 *    bound, so that the join form's distinct "link your Steam account"
 *    answer only reaches somebody the code would otherwise have let in.
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
     * and a join that would take the roster past what one of the team's
     * contests allows.
     */
    protected function addRosterMember(CompetitionTeam $team, string $userId): CompetitionTeamMember
    {
        if (! EligibleRegistration::userMayEnter($team->brand_id, $team->event_id, $userId)) {
            throw CompetitionEntryException::noConfirmedRegistration($userId, $team->event_id);
        }

        if ($team->hasMember($userId)) {
            throw CompetitionEntryException::alreadyOnTeam($userId, (string) $team->getKey());
        }

        $this->guardRosterBounds($team, count($team->memberUserIds()) + 1);
        $this->guardLinkedAccounts($team, $userId);

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
     * Refuses a roster of `$wouldBeSize` if **any** competition this team
     * currently holds a slot in could not field it (design §5.2: "roster size
     * is bounded above by `disciplines.team_size`: a join beyond it is
     * refused. No substitutes in this slice.").
     *
     * Per competition, not one number across all of them. An earlier version
     * took the largest `team_size` the team was entered under, on the theory
     * that a narrower contest would be caught later by
     * `SyncEntrantCompleteness` marking that entry `incomplete`. It would
     * not: completeness asks `roster >= team_size`, so a roster of five
     * against a `team_size` of one reads as *complete* and the 1v1 entrant
     * seeds into its bracket with five people behind a one-player side. The
     * smallest bound is the only one that is always safe, and naming the
     * competition in the refusal is what makes it actionable.
     *
     * No bound at all before the first entry: a team that has entered nothing
     * has nothing to be measured against, and refusing its second member
     * would make the usual order of operations — gather the squad, then
     * enter — impossible.
     *
     * The consequence is deliberate and worth stating: a squad entered in a
     * 1v1 cannot grow to five for the 5v5 beside it. Teams are per event and
     * a person may be on several of them, so the answer is a second team for
     * the solo contest, not a roster that means something different in each
     * bracket.
     */
    protected function guardRosterBounds(CompetitionTeam $team, int $wouldBeSize): void
    {
        foreach ($this->rosterBounds($team) as $competitionId => $teamSize) {
            if ($wouldBeSize > $teamSize) {
                throw CompetitionEntryException::rosterFull(
                    (string) $team->getKey(),
                    $competitionId,
                    $teamSize,
                    $wouldBeSize,
                );
            }
        }
    }

    /**
     * What each contest this team currently holds a slot in can field,
     * tightest first — so the refusal above names the competition that
     * actually cannot take the roster, not merely the first one queried.
     *
     * @return array<string, int> competition ULID => the discipline's team_size
     */
    protected function rosterBounds(CompetitionTeam $team): array
    {
        $competitionIds = $this->slotHeldCompetitionIds($team);

        if ($competitionIds === []) {
            return [];
        }

        $competitions = Competition::withoutBrandScope()->whereIn('id', $competitionIds)->get();

        $teamSizes = Discipline::withoutBrandScope()
            ->whereIn('id', $competitions->pluck('discipline_id')->unique())
            ->pluck('team_size', 'id');

        $bounds = [];

        foreach ($competitions as $competition) {
            $bounds[(string) $competition->getKey()] = max(1, (int) ($teamSizes[$competition->discipline_id] ?? 1));
        }

        asort($bounds);

        return $bounds;
    }

    /**
     * Somebody who joined a roster is no longer on the interested list of any
     * competition that roster is entered in (§5.3).
     */
    protected function retireInterest(CompetitionTeam $team, string $userId): void
    {
        $competitionIds = $this->slotHeldCompetitionIds($team);

        if ($competitionIds === []) {
            return;
        }

        CompetitionInterest::withoutBrandScope()
            ->where('brand_id', $team->brand_id)
            ->whereIn('competition_id', $competitionIds)
            ->where('user_id', $userId)
            ->delete();
    }

    /**
     * Refuses to put `$userId` on a roster when they have not linked a
     * provider that any contest the team holds a slot in requires (§5.6).
     *
     * The measure is the same set of contests as the roster bound: a team
     * that has entered nothing has no requirement yet, so a squad can gather
     * first and enter later, and entering checks the whole roster
     * (`guardRosterLinkedAccounts()`). Only the person joining is asked. The
     * rest of the roster was checked when it entered or joined, and a
     * requirement switched on after that is not retroactive.
     */
    protected function guardLinkedAccounts(CompetitionTeam $team, string $userId): void
    {
        foreach ($this->linkedAccountRequirements($team) as $competitionId => $provider) {
            if (! LinkedIdentity::hasLinked($userId, $provider->value)) {
                throw CompetitionEntryException::linkedAccountMissing([$userId], $provider, $competitionId);
            }
        }
    }

    /**
     * Refuses an entry when any member of the roster entering has not linked
     * the provider the competition's discipline requires (§5.6). Every
     * member who lacks the link is named, so the captain knows who still has
     * to act.
     *
     * @param  list<string>  $roster
     */
    protected function guardRosterLinkedAccounts(Competition $competition, array $roster): void
    {
        $provider = $this->requiredLinkedProvider($competition);

        if ($provider === null) {
            return;
        }

        $missing = LinkedIdentity::missingAmong($roster, $provider->value);

        if ($missing !== []) {
            throw CompetitionEntryException::linkedAccountMissing($missing, $provider, (string) $competition->getKey());
        }
    }

    protected function requiredLinkedProvider(Competition $competition): ?LinkedProvider
    {
        /** @var Discipline|null $discipline */
        $discipline = Discipline::withoutBrandScope()->whereKey($competition->discipline_id)->first();

        return $discipline?->requires_linked_provider;
    }

    /**
     * The provider each contest this team holds a slot in requires, for the
     * contests whose discipline requires one.
     *
     * @return array<string, LinkedProvider> competition ULID => required provider
     */
    protected function linkedAccountRequirements(CompetitionTeam $team): array
    {
        $competitionIds = $this->slotHeldCompetitionIds($team);

        if ($competitionIds === []) {
            return [];
        }

        $competitions = Competition::withoutBrandScope()->whereIn('id', $competitionIds)->get();

        $providers = Discipline::withoutBrandScope()
            ->whereIn('id', $competitions->pluck('discipline_id')->unique())
            ->whereNotNull('requires_linked_provider')
            ->get()
            ->keyBy('id');

        $requirements = [];

        foreach ($competitions as $competition) {
            /** @var Discipline|null $discipline */
            $discipline = $providers->get($competition->discipline_id);

            if ($discipline?->requires_linked_provider !== null) {
                $requirements[(string) $competition->getKey()] = $discipline->requires_linked_provider;
            }
        }

        return $requirements;
    }

    /**
     * The contests this team currently holds a slot in (`incomplete`,
     * `registered` or `checked_in`), which are what the roster bound, the
     * interest retirement and the linked-account rule are measured against.
     *
     * @return list<string>
     */
    protected function slotHeldCompetitionIds(CompetitionTeam $team): array
    {
        return array_values(array_map(strval(...), Entrant::withoutBrandScope()
            ->where('brand_id', $team->brand_id)
            ->where('competition_team_id', $team->getKey())
            ->whereIn('state', Entrant::slotHoldingStates())
            ->pluck('competition_id')
            ->all()));
    }

    protected function syncCompleteness(CompetitionTeam $team): void
    {
        app(SyncEntrantCompleteness::class)->handle($team);
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Competition\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Competition\Actions\AddTeamMember;
use Konventix\Competition\Actions\DeclineIncompleteEntrant;
use Konventix\Competition\Actions\DisqualifyEntrant;
use Konventix\Competition\Actions\FillIncompleteEntrant;
use Konventix\Competition\Actions\MoveTeamMember;
use Konventix\Competition\Actions\RegenerateInviteCode;
use Konventix\Competition\Actions\RemoveTeamMember;
use Konventix\Competition\Actions\RenameTeam;
use Konventix\Competition\Actions\WithdrawEntrant;
use Konventix\Competition\Enums\Permission;
use Konventix\Competition\Enums\WithdrawalReason;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionInterest;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\ParticipantProfile;

/**
 * Operator console: the field of one competition (design §5.2, §5.4, §5.5).
 *
 * This is where §5.4's prompt lives. Closing signup does not resolve anything
 * by itself — it puts the competition in `SignupClosed`, where every
 * incomplete roster is listed with the two choices the design names, each one
 * an Action: **fill up** from the interested list (or any eligible person) or
 * **decline** and free the slot. `start` refuses until none are left, so the
 * prompt cannot be skipped by clicking past it.
 *
 * Beside it sit the organizer powers §5.2 lists, all behind `ManageEntrants`:
 * rename a team, remove a member, move a member between teams, add a person,
 * regenerate a code — plus §5.5's withdraw and disqualify.
 *
 * Runs on the admin host, where `BrandScope` hardens every brand-owned query
 * to zero rows (ADR-0029): every query here escapes it deliberately and
 * states the brand it was handed.
 *
 * Console copy is English.
 */
class EntrantAdminController
{
    public function index(string $competition): Response
    {
        Gate::authorize(Permission::ViewCompetitions->value);

        $model = $this->findCompetition($competition);

        /** @var Discipline|null $discipline */
        $discipline = Discipline::withoutBrandScope()->whereKey($model->discipline_id)->first();
        $teamSize = max(1, $discipline->team_size ?? 1);

        /** @var Collection<int, Entrant> $entrants */
        $entrants = Entrant::withoutBrandScope()
            ->where('brand_id', $model->brand_id)
            ->where('competition_id', $model->getKey())
            ->orderBy('created_at')
            ->get();

        /** @var Collection<int, CompetitionTeam> $teams */
        $teams = CompetitionTeam::withoutBrandScope()
            ->whereIn('id', $entrants->pluck('competition_team_id')->unique())
            ->get()
            ->keyBy('id');

        $interests = CompetitionInterest::forCompetition($model->brand_id, (string) $model->getKey());

        $names = ParticipantProfile::displayNamesFor(array_values(array_merge(
            $teams->flatMap(fn (CompetitionTeam $team): array => $team->memberUserIds())->all(),
            $interests->pluck('user_id')->map(strval(...))->all(),
        )));

        return Inertia::render('admin/competitions/Entrants', [
            'competition' => [
                'id' => (string) $model->getKey(),
                'name' => $model->name,
                'status' => $model->status->value,
                'teamSize' => $teamSize,
                'maxEntrants' => $model->max_entrants,
            ],
            'entrants' => $entrants->map(function (Entrant $entrant) use ($teams, $names, $teamSize): array {
                $team = $teams->get($entrant->competition_team_id);
                $roster = $team?->memberUserIds() ?? [];

                return [
                    'id' => (string) $entrant->getKey(),
                    'state' => $entrant->state->value,
                    'withdrawalReason' => $entrant->withdrawal_reason?->value,
                    'teamId' => $entrant->competition_team_id,
                    'teamName' => $team->name ?? '—',
                    'inviteCode' => $team->invite_code ?? null,
                    'missing' => max(0, $teamSize - count($roster)),
                    'members' => array_map(
                        fn (string $userId): array => [
                            'userId' => $userId,
                            'displayName' => $names[$userId] ?? '—',
                        ],
                        $roster,
                    ),
                ];
            })->values()->all(),
            // The list the fill-up picks from, oldest first, so the person
            // who spoke up first is offered first (§5.3).
            'interested' => $interests->map(fn (CompetitionInterest $interest): array => [
                'userId' => (string) $interest->user_id,
                'displayName' => $names[(string) $interest->user_id] ?? '—',
                'note' => $interest->note,
            ])->values()->all(),
            'teams' => $teams->map(fn (CompetitionTeam $team): array => [
                'id' => (string) $team->getKey(),
                'name' => $team->name,
            ])->values()->all(),
        ]);
    }

    public function fill(Request $request, string $entrant, FillIncompleteEntrant $fill): RedirectResponse
    {
        Gate::authorize(Permission::ManageEntrants->value);

        $validated = $request->validate([
            'userIds' => ['required', 'array', 'min:1'],
            'userIds.*' => ['required', 'ulid'],
        ]);

        $this->run(fn () => $fill->handle($this->findEntrant($entrant), array_values($validated['userIds'])));

        return $this->done('Roster filled.');
    }

    public function decline(string $entrant, DeclineIncompleteEntrant $decline): RedirectResponse
    {
        Gate::authorize(Permission::ManageEntrants->value);

        $this->run(fn () => $decline->handle($this->findEntrant($entrant)));

        return $this->done('Entry declined; the slot is free.');
    }

    public function withdraw(string $entrant, WithdrawEntrant $withdraw): RedirectResponse
    {
        Gate::authorize(Permission::ManageEntrants->value);

        $this->run(fn () => $withdraw->handle($this->findEntrant($entrant), WithdrawalReason::OrganizerWithdrew));

        return $this->done('Entry withdrawn.');
    }

    public function disqualify(string $entrant, DisqualifyEntrant $disqualify): RedirectResponse
    {
        Gate::authorize(Permission::ManageEntrants->value);

        $this->run(fn () => $disqualify->handle($this->findEntrant($entrant)));

        return $this->done('Entry disqualified; its pending matches are forfeited.');
    }

    public function renameTeam(Request $request, string $team, RenameTeam $rename): RedirectResponse
    {
        Gate::authorize(Permission::ManageEntrants->value);

        $validated = $request->validate(['name' => ['required', 'string', 'max:80']]);

        $rename->handle($this->findTeam($team), $validated['name']);

        return $this->done('Team renamed.');
    }

    public function regenerateCode(string $team, RegenerateInviteCode $regenerate): RedirectResponse
    {
        Gate::authorize(Permission::ManageEntrants->value);

        $regenerate->handle($this->findTeam($team));

        return $this->done('New invite code issued.');
    }

    public function addMember(Request $request, string $team, AddTeamMember $add): RedirectResponse
    {
        Gate::authorize(Permission::ManageEntrants->value);

        $validated = $request->validate(['userId' => ['required', 'ulid']]);

        $this->run(fn () => $add->handle($this->findTeam($team), $validated['userId']));

        return $this->done('Player added.');
    }

    public function removeMember(Request $request, string $team, RemoveTeamMember $remove): RedirectResponse
    {
        Gate::authorize(Permission::ManageEntrants->value);

        $validated = $request->validate(['userId' => ['required', 'ulid']]);

        $this->run(fn () => $remove->handle($this->findTeam($team), $validated['userId']));

        return $this->done('Player removed.');
    }

    public function moveMember(Request $request, string $team, MoveTeamMember $move): RedirectResponse
    {
        Gate::authorize(Permission::ManageEntrants->value);

        $validated = $request->validate([
            'userId' => ['required', 'ulid'],
            'toTeamId' => ['required', 'ulid'],
        ]);

        $this->run(fn () => $move->handle(
            $this->findTeam($team),
            $this->findTeam($validated['toTeamId']),
            $validated['userId'],
        ));

        return $this->done('Player moved.');
    }

    /**
     * A refused roster move is a field error, not a 500: the organizer picked
     * a person who is not registered, or a team that is already full, and
     * that is something to be told in the panel they are standing in.
     *
     * @param  callable(): mixed  $work
     */
    private function run(callable $work): void
    {
        try {
            $work();
        } catch (CompetitionEntryException $e) {
            throw ValidationException::withMessages(['entrant' => $e->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    private function findCompetition(string $competition): Competition
    {
        /** @var Competition */
        return Competition::withoutBrandScope()->findOrFail($competition);
    }

    private function findEntrant(string $entrant): Entrant
    {
        /** @var Entrant */
        return Entrant::withoutBrandScope()->findOrFail($entrant);
    }

    private function findTeam(string $team): CompetitionTeam
    {
        /** @var CompetitionTeam */
        return CompetitionTeam::withoutBrandScope()->findOrFail($team);
    }
}

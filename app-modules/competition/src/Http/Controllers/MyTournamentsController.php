<?php

declare(strict_types=1);

namespace Konventix\Competition\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Competition\Actions\CreateTeam;
use Konventix\Competition\Actions\LeaveTeam;
use Konventix\Competition\Actions\RegenerateInviteCode;
use Konventix\Competition\Actions\WithdrawEntrant;
use Konventix\Competition\Enums\WithdrawalReason;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\CompetitionTeamMember;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\ParticipantProfile;
use Konventix\Platform\Brands\BrandContext;

/**
 * "Meine Turniere" (design §10): the person's teams and entries, the invite
 * code their captaincy entitles them to see, and the three things they may do
 * about it — leave a team, regenerate the code, withdraw the entry.
 *
 * **Authorization is membership and nothing else.** No capability, because
 * there is none to grant: reading back the teams you are on is not a power.
 * Anything that is not yours is a 404 rather than a 403 — a 403 on a ULID
 * confirms the ULID names something.
 *
 * Names come from `ParticipantProfile` (§10), this module's own read model
 * projected from `UserProfileUpdated`. Identity's `User` is never imported
 * here or anywhere in this module; the signed-in person is a ULID from the
 * guard.
 *
 * Copy is German — this is a participant page (ADR-0001).
 */
final class MyTournamentsController
{
    public function index(Request $request): Response
    {
        $userId = $this->actorId($request);
        $brandId = $this->brandId();

        $teamIds = CompetitionTeamMember::query()
            ->where('user_id', $userId)
            ->pluck('competition_team_id');

        /** @var Collection<int, CompetitionTeam> $teams */
        $teams = CompetitionTeam::query()
            ->whereIn('id', $teamIds)
            ->orderBy('name')
            ->get();

        $entrants = Entrant::query()
            ->whereIn('competition_team_id', $teams->modelKeys())
            ->get()
            ->groupBy('competition_team_id');

        $competitions = Competition::query()
            ->whereIn('id', $entrants->flatten()->pluck('competition_id')->unique())
            ->get()
            ->keyBy('id');

        $rosterNames = ParticipantProfile::displayNamesFor(
            array_values($teams->flatMap(fn (CompetitionTeam $team): array => $team->memberUserIds())->all())
        );

        return Inertia::render('competition/my/Tournaments', [
            'brandId' => $brandId,
            'teams' => $teams->map(function (CompetitionTeam $team) use (
                $userId,
                $entrants,
                $competitions,
                $rosterNames,
            ): array {
                $isCaptain = $team->isCaptain($userId);

                return [
                    'id' => (string) $team->getKey(),
                    'name' => $team->name,
                    'eventId' => $team->event_id,
                    'isCaptain' => $isCaptain,
                    // The code is a shared secret: only the captain sees it,
                    // because only the captain is accountable for where it
                    // ends up (§5.2).
                    'inviteCode' => $isCaptain ? $team->invite_code : null,
                    'members' => array_map(
                        fn (string $memberId): array => [
                            'userId' => $memberId,
                            'displayName' => $rosterNames[$memberId] ?? '—',
                            'isYou' => $memberId === $userId,
                        ],
                        $team->memberUserIds(),
                    ),
                    'entries' => $entrants->get((string) $team->getKey(), collect())
                        ->map(fn (Entrant $entrant): array => [
                            'id' => (string) $entrant->getKey(),
                            'state' => $entrant->state->value,
                            'withdrawalReason' => $entrant->withdrawal_reason?->value,
                            'withdrawalLabel' => $entrant->withdrawal_reason?->label(),
                            'competitionName' => $competitions->get($entrant->competition_id)->name ?? '—',
                            'competitionStatus' => $competitions->get($entrant->competition_id)?->status->value,
                        ])
                        ->values()
                        ->all(),
                ];
            })->values()->all(),
        ]);
    }

    public function storeTeam(Request $request, CreateTeam $createTeam): RedirectResponse
    {
        $validated = $request->validate([
            'eventId' => ['required', 'ulid'],
            'name' => ['required', 'string', 'max:80'],
        ]);

        try {
            $createTeam->handle(
                $this->brandId(),
                $validated['eventId'],
                $validated['name'],
                $this->actorId($request),
            );
        } catch (CompetitionEntryException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team angelegt.')]);

        return back();
    }

    public function leave(Request $request, string $team, LeaveTeam $leaveTeam): RedirectResponse
    {
        $userId = $this->actorId($request);
        $model = $this->findOwnTeam($team, $userId);

        $leaveTeam->handle($model, $userId);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Du hast das Team verlassen.')]);

        return back();
    }

    public function regenerateCode(Request $request, string $team, RegenerateInviteCode $regenerate): RedirectResponse
    {
        $userId = $this->actorId($request);
        $model = $this->findOwnTeam($team, $userId);

        // Only the captain, per §5.2. A member regenerating the code would be
        // locking their own team out of Discord.
        abort_unless($model->isCaptain($userId), 403);

        $regenerate->handle($model);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Neuer Einladungscode erzeugt.')]);

        return back();
    }

    /**
     * The captain pulls the team out of one competition (§5.5). Until
     * `Running` only — the Action refuses later, and the refusal comes back
     * as a message rather than a 500.
     */
    public function withdraw(Request $request, string $entrant, WithdrawEntrant $withdraw): RedirectResponse
    {
        $userId = $this->actorId($request);

        /** @var Entrant|null $model */
        $model = Entrant::query()->whereKey($entrant)->first();
        abort_if($model === null, 404);

        /** @var CompetitionTeam|null $team */
        $team = CompetitionTeam::query()->whereKey($model->competition_team_id)->first();
        abort_if($team === null || ! $team->hasMember($userId), 404);
        abort_unless($team->isCaptain($userId), 403);

        try {
            $withdraw->handle($model, WithdrawalReason::CaptainWithdrew);
        } catch (CompetitionEntryException $e) {
            return back()->withErrors(['entrant' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Anmeldung zurückgezogen.')]);

        return back();
    }

    private function findOwnTeam(string $team, string $userId): CompetitionTeam
    {
        /** @var CompetitionTeam|null $model */
        $model = CompetitionTeam::query()->whereKey($team)->first();

        // A team you are not on is a 404, not a 403.
        abort_if($model === null || ! $model->hasMember($userId), 404);

        return $model;
    }

    private function actorId(Request $request): string
    {
        $user = $request->user();
        abort_if($user === null, 403);

        return (string) $user->getAuthIdentifier();
    }

    private function brandId(): string
    {
        return (string) app(BrandContext::class)->currentOrFail()->getKey();
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Competition\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Competition\Actions\CheckInEntrant;
use Konventix\Competition\Actions\UndoCheckIn;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\Permission;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\AdmissionPresence;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\ParticipantProfile;

/**
 * The desk's check-in list (design §6, §9).
 *
 * §9's `…/{id}/desk` screen is a whole tournament cockpit — seeding, the
 * bracket, results, scheduling — and it is built by §9's own slice. This is
 * the check-in half of it, on its own route, so the two do not race for the
 * same file: a later task can fold this panel into the cockpit or link to it,
 * and neither has to be rewritten to make room.
 *
 * **What it renders is the one question the desk asks**: who is here, and who
 * may be let in. With `requires_event_check_in` each roster shows which
 * members the door has seen — read from `competition_admission_presence`,
 * this module's own projection of Admission's public events, never from
 * Admission's tables (ADR-0004) — so the person at the table can say "your
 * two are still outside" instead of clicking a button that refuses.
 *
 * The desk may check in or undo **whatever the mode** (§6), so the buttons
 * are not conditioned on `check_in_mode`; the mode is shown as context, and
 * the self-service window is shown so the desk knows whether captains can
 * already do it themselves.
 *
 * Runs on the admin host, where `BrandScope` hardens every brand-owned query
 * to zero rows (ADR-0029): every query escapes it and states the brand.
 *
 * Console copy is English.
 */
class CheckInDeskController
{
    public function index(string $competition): Response
    {
        Gate::authorize(Permission::CheckInEntrants->value);

        $model = $this->findCompetition($competition);

        /** @var Collection<int, Entrant> $entrants */
        $entrants = Entrant::withoutBrandScope()
            ->where('brand_id', $model->brand_id)
            ->where('competition_id', $model->getKey())
            ->whereIn('state', [EntrantState::Registered->value, EntrantState::CheckedIn->value])
            ->orderBy('created_at')
            ->get();

        /** @var Collection<int, CompetitionTeam> $teams */
        $teams = CompetitionTeam::withoutBrandScope()
            ->whereIn('id', $entrants->pluck('competition_team_id')->unique())
            ->get()
            ->keyBy('id');

        /** @var array<string, list<string>> $rosters */
        $rosters = [];

        foreach ($entrants as $entrant) {
            $rosters[(string) $entrant->getKey()] = $teams->get($entrant->competition_team_id)?->memberUserIds() ?? [];
        }

        /** @var list<string> $everyone */
        $everyone = array_values(array_unique(array_merge([], ...array_values($rosters))));

        $names = ParticipantProfile::displayNamesFor($everyone);

        // One query for the whole field rather than one per team: a desk list
        // of forty rosters would otherwise be forty round trips while
        // somebody waits at the table.
        $atTheDoor = $model->requires_event_check_in
            ? AdmissionPresence::seenAtTheDoor($model->brand_id, $model->event_id, $everyone)
            : $everyone;

        return Inertia::render('admin/competitions/desk/CheckIn', [
            'competition' => [
                'id' => (string) $model->getKey(),
                'name' => $model->name,
                'status' => $model->status->value,
                'checkInMode' => $model->check_in_mode->value,
                'checkInModeLabel' => $model->check_in_mode->label(),
                'requiresEventCheckIn' => $model->requires_event_check_in,
                'checkInOpensMinutesBefore' => $model->check_in_opens_minutes_before,
                // A null start is why the self-service window can never
                // open (design §6); the page says so rather than hiding it.
                'startsAt' => $model->starts_at?->toIso8601String(),
                'selfServiceOpensAt' => $model->selfServiceCheckInOpensAt()?->toIso8601String(),
                'selfServiceIsOpen' => $model->selfServiceCheckInIsOpen(),
            ],
            'entrants' => $entrants->map(function (Entrant $entrant) use (
                $teams,
                $rosters,
                $names,
                $atTheDoor,
                $model,
            ): array {
                $roster = $rosters[(string) $entrant->getKey()];

                return [
                    'id' => (string) $entrant->getKey(),
                    'state' => $entrant->state->value,
                    'teamName' => $teams->get($entrant->competition_team_id)->name ?? '—',
                    'checkedInAt' => $entrant->checked_in_at?->toIso8601String(),
                    'ready' => $entrant->isReadyFor($model->check_in_mode),
                    'members' => array_map(
                        fn (string $userId): array => [
                            'userId' => $userId,
                            'displayName' => $names[$userId] ?? '—',
                            'atTheDoor' => in_array($userId, $atTheDoor, true),
                        ],
                        $roster,
                    ),
                ];
            })->values()->all(),
        ]);
    }

    public function checkIn(string $entrant, CheckInEntrant $checkIn): RedirectResponse
    {
        Gate::authorize(Permission::CheckInEntrants->value);

        $this->run(fn () => $checkIn->handle($this->findEntrant($entrant)));

        return $this->done('Checked in.');
    }

    public function undo(string $entrant, UndoCheckIn $undo): RedirectResponse
    {
        Gate::authorize(Permission::CheckInEntrants->value);

        $this->run(fn () => $undo->handle($this->findEntrant($entrant)));

        return $this->done('Check-in undone.');
    }

    /**
     * A refused check-in is a field error, not a 500: "two of them are still
     * outside" is the answer the desk needs, in the panel it is standing in.
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
}

<?php

declare(strict_types=1);

namespace Konventix\Competition\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Competition\Actions\EnterCompetition;
use Konventix\Competition\Actions\SubmitScoreEntry;
use Konventix\Competition\Data\EnterCompetitionData;
use Konventix\Competition\Data\ScoreEntryData;
use Konventix\Competition\Http\Controllers\Concerns\AnswersTheParticipant;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\EligibleRegistration;
use Konventix\Competition\Pages\TournamentPages;

/**
 * A competition's public page (design §10) and the two participant writes
 * that hang off it: a captain entering a team (§5.2), and a leaderboard
 * score (§8.3).
 *
 * The page is public and brand-scoped (`TournamentPages`). The writes are
 * `auth` and throttled (routes file); who may make them is membership, never
 * a capability (§11), and the entrant is always the viewer's own — derived
 * from their roster, never taken from the request, so nobody can enter or
 * score for a team they are not on.
 */
final class CompetitionPageController
{
    use AnswersTheParticipant;

    public function show(Request $request, string $slug, string $competition, TournamentPages $pages): Response
    {
        $event = $pages->event($slug);

        return Inertia::render('competition/Competition', [
            'page' => $pages->competitionPage($event, $pages->competition($event, $competition), $this->viewerId($request)),
        ]);
    }

    /**
     * The captain enters one of their teams (§5.2). A team they do not
     * captain is a 404: a 403 would say the ULID names a team.
     */
    public function enter(Request $request, string $competition, EnterCompetition $enter): RedirectResponse
    {
        $model = $this->find($competition);
        $data = EnterCompetitionData::validateAndCreate($request->all());
        $userId = $this->actorId($request);

        /** @var CompetitionTeam|null $team */
        $team = CompetitionTeam::query()->whereKey($data->teamId)->first();
        abort_if($team === null || ! $team->isCaptain($userId), 404);

        // §5.1: everyone who enters holds a confirmed registration for the
        // event. The roster is handed to the Action as the entering members,
        // which is what makes `EnterCompetition` check each of them — a
        // registration cancelled after the team was formed must not still
        // get the team in. Asked here first only to answer in German with
        // one sentence rather than the Action's English cause.
        $roster = $team->memberUserIds();

        foreach ($roster as $memberId) {
            if (! EligibleRegistration::userMayEnter($model->brand_id, $model->event_id, $memberId)) {
                throw ValidationException::withMessages([
                    'teamId' => __('Alle im Team brauchen eine bestätigte Anmeldung zur Veranstaltung, bevor ihr euch anmelden könnt.'),
                ]);
            }
        }

        $this->attempt(fn () => $enter->handle($model, $team, $roster), 'teamId');

        return $this->done(__('Euer Team ist angemeldet.'));
    }

    /**
     * A score for the viewer's own entrant on a leaderboard (§8.3). It counts
     * once the desk has verified it.
     */
    public function submitScore(Request $request, string $competition, TournamentPages $pages, SubmitScoreEntry $submit): RedirectResponse
    {
        $model = $this->find($competition);
        $userId = $this->actorId($request);

        $entrant = $pages->ownEntrant($model, $userId);
        $stage = $pages->stageOf($model);
        abort_if($entrant === null || $stage === null, 404);

        $data = ScoreEntryData::validateAndCreate($request->all());

        $this->attempt(fn () => $submit->handle($stage, $entrant, $data, $userId), 'value');

        return $this->done(__('Ergebnis eingereicht. Es zählt, sobald die Orga es bestätigt hat.'));
    }

    /**
     * A competition of this brand that the public may see; anything else is a
     * 404 (§4).
     */
    private function find(string $competition): Competition
    {
        /** @var Competition|null $model */
        $model = Competition::query()->whereKey($competition)->first();
        abort_if($model === null || ! $model->status->isPubliclyVisible(), 404);

        return $model;
    }
}

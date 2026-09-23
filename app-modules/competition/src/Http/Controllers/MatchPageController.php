<?php

declare(strict_types=1);

namespace Konventix\Competition\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Competition\Actions\ConfirmMatchResult;
use Konventix\Competition\Actions\DisputeMatchResult;
use Konventix\Competition\Actions\ReportMatchResult;
use Konventix\Competition\Data\DisputeResultData;
use Konventix\Competition\Data\MatchReportData;
use Konventix\Competition\Http\Controllers\Concerns\AnswersTheParticipant;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\EventSummary;
use Konventix\Competition\Pages\TournamentPages;

/**
 * A match's page (design §10) and §8.2/§8.4's three participant writes:
 * report, confirm, dispute.
 *
 * The page is public; its history and its buttons are for a member of a side
 * (`TournamentPages::matchPage`). The writes are `auth` and throttled
 * (routes file), and the side they speak for is the viewer's own — the
 * entrant in one of the match's slots whose roster they are on, derived here
 * and never taken from the request. Somebody on no side gets a 404.
 *
 * A dispute raised here lands where the desk already looks: the match's
 * `disputed_at` and its history, which the desk's open-dispute panel reads
 * (§8.4, Task 6). There is no participant-side dispute the desk cannot see.
 */
final class MatchPageController
{
    use AnswersTheParticipant;

    public function show(Request $request, string $slug, string $competition, string $match, TournamentPages $pages): Response
    {
        $event = $pages->event($slug);

        return Inertia::render('competition/Match', [
            'page' => $pages->matchPage($event, $pages->competition($event, $competition), $match, $this->viewerId($request)),
        ]);
    }

    public function report(Request $request, string $competition, string $match, TournamentPages $pages, ReportMatchResult $report): RedirectResponse
    {
        [$model, $side, $userId] = $this->resolveSide($request, $competition, $match, $pages);
        $data = MatchReportData::validateAndCreate($request->all());

        $this->attempt(fn () => $report->handle($model, $side, $data, $userId), 'scores');

        return $this->done(__('Ergebnis gemeldet.'));
    }

    public function confirm(Request $request, string $competition, string $match, TournamentPages $pages, ConfirmMatchResult $confirm): RedirectResponse
    {
        [$model, $side, $userId] = $this->resolveSide($request, $competition, $match, $pages);

        $this->attempt(fn () => $confirm->handle($model, $side, $userId), 'result');

        return $this->done(__('Ergebnis bestätigt.'));
    }

    public function dispute(Request $request, string $competition, string $match, TournamentPages $pages, DisputeMatchResult $dispute): RedirectResponse
    {
        [$model, $side, $userId] = $this->resolveSide($request, $competition, $match, $pages);
        $data = DisputeResultData::validateAndCreate($request->all());

        $this->attempt(fn () => $dispute->handle($model, $side, $data->reason, $userId), 'reason');

        return $this->done(__('Ergebnis angefochten. Die Turnierleitung entscheidet.'));
    }

    /**
     * The match (of a publicly visible competition of this brand) and the
     * viewer's side of it — a 404 for anything else.
     *
     * @return array{0: CompetitionMatch, 1: Entrant, 2: string}
     */
    private function resolveSide(Request $request, string $competition, string $match, TournamentPages $pages): array
    {
        /** @var Competition|null $owner */
        $owner = Competition::query()->whereKey($competition)->first();
        abort_if($owner === null
            || ! $owner->status->isPubliclyVisible()
            || ! EventSummary::isPublicEvent($owner->event_id), 404);

        /** @var CompetitionMatch|null $model */
        $model = CompetitionMatch::query()
            ->where('competition_id', $owner->id)
            ->whereKey($match)
            ->first();
        abort_if($model === null, 404);

        $userId = $this->actorId($request);
        $side = $pages->ownSide($model, $userId);
        abort_if($side === null, 404);

        return [$model, $side, $userId];
    }
}

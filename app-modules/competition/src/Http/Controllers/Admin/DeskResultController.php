<?php

declare(strict_types=1);

namespace Konventix\Competition\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Konventix\Competition\Actions\CorrectMatchResult;
use Konventix\Competition\Actions\RecordResultAtDesk;
use Konventix\Competition\Actions\RejectScoreEntry;
use Konventix\Competition\Actions\ResolveDispute;
use Konventix\Competition\Actions\SubmitScoreEntry;
use Konventix\Competition\Actions\VerifyScoreEntry;
use Konventix\Competition\Data\DeskScoreLineData;
use Konventix\Competition\Data\ScoreEntryData;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\Permission;
use Konventix\Competition\Http\Controllers\Admin\Concerns\AnswersTheDesk;
use Konventix\Competition\Http\Controllers\Admin\Concerns\ScopesToOperator;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\ScoreEntry;
use Konventix\Competition\Models\Stage;

/**
 * The tournament desk's results (design §8, §9): the per-match record,
 * correct and resolve dialogs, and a leaderboard's verification queue.
 *
 * Capabilities as §8 and §11 hand them out: recording, resolving a dispute
 * before a result exists, and working the queue are `RecordResults`;
 * correcting, and resolving a dispute raised against a result that already
 * stands, are `CorrectResults` (§8.4 "`RecordResults` before a result exists,
 * `CorrectResults` after"). The participant side of §8 — report, confirm,
 * dispute — is not here; it is §10's.
 *
 * No endpoint here takes an upload. A proof already attached to a report or
 * a score is shown to the desk; offering the desk a way to add one would
 * put images behind a read Platform does not guard yet (§8.7, #322).
 *
 * Every row this is handed must belong to one of the admin host's own brands
 * (`ScopesToOperator`); the capability check is always the first line.
 *
 * Console copy is English.
 */
class DeskResultController
{
    use AnswersTheDesk;
    use ScopesToOperator;

    /** The desk records a head-to-head result itself (§8.1, §8.2 step 3). */
    public function record(Request $request, string $match, RecordResultAtDesk $record): RedirectResponse
    {
        Gate::authorize(Permission::RecordResults->value);

        $model = $this->findMatch($match);
        $data = DeskScoreLineData::validateAndCreate($request->all());

        $this->attempt(fn () => $record->handle(
            $model,
            $data->scoresByPosition(),
            $data->winnerEntrantId,
            $this->actorId($request),
        ));

        return $this->done('Result recorded.');
    }

    /**
     * Rewrite a finished match's result (§8.5). The note is mandatory the
     * moment the correction voids a downstream result, and
     * `CorrectMatchResult` says so as a field error on `note`.
     */
    public function correct(Request $request, string $match, CorrectMatchResult $correct): RedirectResponse
    {
        Gate::authorize(Permission::CorrectResults->value);

        $model = $this->findMatch($match);
        $data = DeskScoreLineData::validateAndCreate($request->all());

        $this->attempt(fn () => $correct->handle(
            $model,
            $data->scoresByEntrant(),
            $data->winnerEntrantId,
            $this->actorId($request),
            $data->note,
        ));

        return $this->done('Result corrected.');
    }

    /**
     * Decide a disputed match with the note both sides are shown (§8.4).
     * Which capability that takes depends on whether a result already stands,
     * so the match is read first — after a check that the viewer holds at
     * least one of the two, which keeps a stranger's answer a 403.
     */
    public function resolve(Request $request, string $match, ResolveDispute $resolve): RedirectResponse
    {
        abort_unless(Gate::any([Permission::RecordResults->value, Permission::CorrectResults->value]), 403);

        $model = $this->findMatch($match);

        Gate::authorize($model->state === MatchState::Finished
            ? Permission::CorrectResults->value
            : Permission::RecordResults->value);

        $data = DeskScoreLineData::validateAndCreate($request->all());

        $this->attempt(fn () => $resolve->handle(
            $model,
            $data->scoresByPosition(),
            $data->winnerEntrantId,
            $this->actorId($request),
            (string) $data->note,
        ));

        return $this->done('Dispute resolved.');
    }

    /** A score the desk enters itself is verified on creation (§8.3). */
    public function enterScore(Request $request, string $stage, SubmitScoreEntry $submit): RedirectResponse
    {
        Gate::authorize(Permission::RecordResults->value);

        /** @var Stage $stageModel */
        $stageModel = Stage::withoutBrandScope()->findOrFail($stage);

        $this->ensureOperatorOwns($stageModel->brand_id);

        $entrantId = $request->validate(['entrantId' => ['required', 'ulid']])['entrantId'];
        $data = ScoreEntryData::validateAndCreate([...$request->all(), 'proofMediaId' => null]);

        /** @var Entrant $entrant */
        $entrant = Entrant::withoutBrandScope()
            ->where('brand_id', $stageModel->brand_id)
            ->where('competition_id', $stageModel->competition_id)
            ->findOrFail($entrantId);

        $submit->handle($stageModel, $entrant, $data, $this->actorId($request), asOrganizer: true);

        return $this->done('Score entered.');
    }

    public function verify(Request $request, string $entry, VerifyScoreEntry $verify): RedirectResponse
    {
        Gate::authorize(Permission::RecordResults->value);

        $verify->handle($this->findEntry($entry), $this->actorId($request));

        return $this->done('Score verified.');
    }

    public function reject(Request $request, string $entry, RejectScoreEntry $reject): RedirectResponse
    {
        Gate::authorize(Permission::RecordResults->value);

        $model = $this->findEntry($entry);
        $note = $request->validate(['rejectionNote' => ['required', 'string', 'max:255']])['rejectionNote'];

        $reject->handle($model, $this->actorId($request), $note);

        return $this->done('Score rejected; the participant sees why.');
    }

    private function findMatch(string $match): CompetitionMatch
    {
        /** @var CompetitionMatch $model */
        $model = CompetitionMatch::withoutBrandScope()->findOrFail($match);

        $this->ensureOperatorOwns($model->brand_id);

        return $model;
    }

    private function findEntry(string $entry): ScoreEntry
    {
        /** @var ScoreEntry $model */
        $model = ScoreEntry::withoutBrandScope()->findOrFail($entry);

        $this->ensureOperatorOwns($model->brand_id);

        return $model;
    }
}

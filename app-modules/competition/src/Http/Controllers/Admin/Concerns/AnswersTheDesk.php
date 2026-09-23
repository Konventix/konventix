<?php

declare(strict_types=1);

namespace Konventix\Competition\Http\Controllers\Admin\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Konventix\Competition\Exceptions\CompetitionLifecycleException;
use Konventix\Competition\Exceptions\IllegalMatchStateTransitionException;
use Konventix\Competition\Exceptions\ResultSubmissionException;
use Konventix\Competition\Exceptions\StageDrawRefusedException;

/**
 * How the tournament desk's endpoints answer (design §9).
 *
 * A refusal from an Action is the answer to the button the desk pressed —
 * "that stage already has a result", "that match is disputed, resolve it",
 * "that match is already finished", "that competition is over" — so it comes back as a field error on
 * `desk`, shown above the panel, rather than as a 500. Only the module's own
 * named refusals are translated; anything else is a real failure and stays
 * one.
 */
trait AnswersTheDesk
{
    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function attempt(callable $work): mixed
    {
        try {
            return $work();
        } catch (StageDrawRefusedException|CompetitionLifecycleException|IllegalMatchStateTransitionException|ResultSubmissionException $e) {
            throw ValidationException::withMessages(['desk' => $e->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    private function actorId(Request $request): string
    {
        return (string) ($request->user() ?? abort(401))->getAuthIdentifier();
    }
}

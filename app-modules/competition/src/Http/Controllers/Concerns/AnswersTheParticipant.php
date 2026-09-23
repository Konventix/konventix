<?php

declare(strict_types=1);

namespace Konventix\Competition\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Exceptions\CompetitionLifecycleException;
use Konventix\Competition\Exceptions\IllegalMatchStateTransitionException;
use Konventix\Competition\Exceptions\ResultSubmissionException;

/**
 * How a participant write answers (design §8, §10): a refusal the person can
 * act on — the desk keeps results to itself, the match was settled while they
 * were typing, signup has closed — comes back as a form error on the field
 * the form will show it beside (so the input can point at it, ADR-0055),
 * never as a 500; success as a German toast (ADR-0001).
 *
 * The refusals' own causes are still English; their translation is one pass
 * across the module (Task 8), so they are rendered as they are.
 */
trait AnswersTheParticipant
{
    private function attempt(callable $work, string $field): mixed
    {
        try {
            return $work();
        } catch (ResultSubmissionException|CompetitionEntryException|CompetitionLifecycleException|IllegalMatchStateTransitionException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
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

    private function viewerId(Request $request): ?string
    {
        $user = $request->user();

        return $user === null ? null : (string) $user->getAuthIdentifier();
    }
}

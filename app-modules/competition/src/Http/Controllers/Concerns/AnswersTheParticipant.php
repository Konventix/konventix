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
 * The participant refusals' own causes (`ResultSubmissionException`,
 * `CompetitionEntryException`) are still English; their translation is one
 * pass across the module (Task 8), so they are rendered as they are. The two
 * exceptions written for the desk are never shown: they get a fixed German
 * sentence each.
 */
trait AnswersTheParticipant
{
    private function attempt(callable $work, string $field): mixed
    {
        try {
            return $work();
        } catch (ResultSubmissionException|CompetitionEntryException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        } catch (CompetitionLifecycleException) {
            // The desk's refusal, in the desk's English with a ULID in it. The
            // one cause a participant reaches is `over()` — the competition
            // finished or was called off while their page was open — so it
            // gets one German sentence and no internals.
            throw ValidationException::withMessages([
                $field => __('Dieses Turnier ist vorbei und nimmt keine Ergebnisse mehr an.'),
            ]);
        } catch (IllegalMatchStateTransitionException) {
            // The match moved on under them — the desk recorded it, or the
            // other side confirmed a moment earlier.
            throw ValidationException::withMessages([
                $field => __('Dieses Spiel hat sich inzwischen geändert. Lade die Seite neu.'),
            ]);
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

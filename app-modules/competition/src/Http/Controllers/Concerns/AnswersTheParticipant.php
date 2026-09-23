<?php

declare(strict_types=1);

namespace Konventix\Competition\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Konventix\Competition\Enums\LinkedProvider;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Exceptions\CompetitionLifecycleException;
use Konventix\Competition\Exceptions\IllegalMatchStateTransitionException;
use Konventix\Competition\Exceptions\ResultSubmissionException;
use Konventix\Competition\Models\ParticipantProfile;

/**
 * How a participant write answers (design §8, §10): a refusal the person can
 * act on — the desk keeps results to itself, the match was settled while they
 * were typing, signup has closed — comes back as a form error on the field
 * the form will show it beside (so the input can point at it, ADR-0055),
 * never as a 500; success as a German toast (ADR-0001).
 *
 * Every refusal is German. The two participant exceptions
 * (`ResultSubmissionException`, `CompetitionEntryException`) carry a German
 * sentence per cause beside their English message, and this answers with
 * that sentence (`participantMessage()`). The English message is the desk's
 * and the log's. The two exceptions written for the desk are never shown:
 * they get a fixed German sentence each.
 */
trait AnswersTheParticipant
{
    /**
     * `$viewerId` lets the linked-account refusal (§5.6) say "du" to the
     * person who pressed the button rather than naming them.
     */
    private function attempt(callable $work, string $field, ?string $viewerId = null): mixed
    {
        try {
            return $work();
        } catch (CompetitionEntryException $e) {
            // The linked-account rule (§5.6) is answered with the names of
            // whoever still lacks the link; the page shows the link to the
            // account settings beside it.
            $provider = $e->missingLinkedProvider();

            throw ValidationException::withMessages([
                $field => $provider === null
                    ? $e->participantMessage()
                    : $this->linkedAccountRefusal($provider, $e->usersMissingTheLink(), $viewerId),
            ]);
        } catch (ResultSubmissionException $e) {
            throw ValidationException::withMessages([$field => $e->participantMessage()]);
        } catch (CompetitionLifecycleException) {
            // The desk's refusal, in the desk's English with a ULID in it. The
            // one cause a participant reaches is `over()` — the competition
            // finished or was called off while their page was open — so it
            // gets one German sentence and no internals.
            throw ValidationException::withMessages([
                $field => __('This tournament is over and takes no more results.'),
            ]);
        } catch (IllegalMatchStateTransitionException) {
            // The match moved on under them — the desk recorded it, or the
            // other side confirmed a moment earlier.
            throw ValidationException::withMessages([
                $field => __('This match has changed in the meantime. Reload the page.'),
            ]);
        }
    }

    /**
     * The German answer to the linked-account rule (design §5.6). When the
     * viewer is the only one missing the link, they are told so directly;
     * otherwise the teammates who still have to link an account are named,
     * because only they can fix it.
     *
     * @param  list<string>  $missingUserIds
     */
    private function linkedAccountRefusal(LinkedProvider $provider, array $missingUserIds, ?string $viewerId): string
    {
        if ($viewerId !== null && $missingUserIds === [$viewerId]) {
            return __('This tournament needs you to have a linked :provider account.', [
                'provider' => $provider->label(),
            ]);
        }

        $names = [];

        foreach (ParticipantProfile::displayNamesFor($missingUserIds) as $userId => $name) {
            $names[] = $userId === $viewerId ? __('you') : $name;
        }

        return __('This tournament needs a linked :provider account for everyone on the team – still missing: :names.', [
            'provider' => $provider->label(),
            'names' => implode(', ', $names),
        ]);
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

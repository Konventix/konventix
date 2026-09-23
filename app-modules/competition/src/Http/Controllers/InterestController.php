<?php

declare(strict_types=1);

namespace Konventix\Competition\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Konventix\Competition\Actions\ExpressInterest;
use Konventix\Competition\Actions\WithdrawInterest;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\EventSummary;

/**
 * The public "Ich würde mitspielen" list (design §5.3).
 *
 * Two endpoints, no page of its own: the list renders on the competition
 * page next to the entered teams, because its whole purpose is that people
 * looking for a team and teams looking for people are reading the same
 * screen.
 *
 * The gate is §5.1's — a confirmed registration, or since #330 a ticket in
 * one's own name (§5.1 addendum) — and it lives in the Action,
 * so the console's own paths obey it too. A refusal comes back as a field
 * error on `note`, which is the only field the form has.
 *
 * Copy is German (ADR-0001).
 */
final class InterestController
{
    public function store(Request $request, string $competition, ExpressInterest $express): RedirectResponse
    {
        $validated = $request->validate([
            // §5.3 caps the note at 140 characters — a line, not an essay,
            // and the column agrees.
            'note' => ['nullable', 'string', 'max:140'],
        ]);

        $model = $this->find($competition);
        $user = $request->user();
        abort_if($user === null, 403);

        try {
            $express->handle($model, (string) $user->getAuthIdentifier(), $validated['note'] ?? null);
        } catch (CompetitionEntryException $e) {
            return back()->withErrors(['note' => $e->participantMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You are now on the interest list.')]);

        return back();
    }

    public function destroy(Request $request, string $competition, WithdrawInterest $withdraw): RedirectResponse
    {
        $model = $this->find($competition);
        $user = $request->user();
        abort_if($user === null, 403);

        $withdraw->handle($model, (string) $user->getAuthIdentifier());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You are no longer on the list.')]);

        return back();
    }

    /**
     * Brand-scoped by the ambient `BrandScope` on the brand host, so a
     * competition of another brand is simply not there — a 404, and no
     * answer about whether it exists. A draft competition, and any
     * competition of an event that is not public, is not there either: the
     * page's rule, as in the other participant controllers (final review,
     * part 2, M4; fix wave B, item 3).
     */
    private function find(string $competition): Competition
    {
        /** @var Competition|null $model */
        $model = Competition::query()->whereKey($competition)->first();
        abort_if($model === null
            || ! $model->status->isPubliclyVisible()
            || ! EventSummary::isPublicEvent($model->event_id), 404);

        return $model;
    }
}

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

/**
 * The public "Ich würde mitspielen" list (design §5.3).
 *
 * Two endpoints, no page of its own: the list renders on the competition
 * page next to the entered teams, because its whole purpose is that people
 * looking for a team and teams looking for people are reading the same
 * screen.
 *
 * The gate is §5.1's — a confirmed registration — and it lives in the Action,
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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Du stehst jetzt auf der Interessenten-Liste.')]);

        return back();
    }

    public function destroy(Request $request, string $competition, WithdrawInterest $withdraw): RedirectResponse
    {
        $model = $this->find($competition);
        $user = $request->user();
        abort_if($user === null, 403);

        $withdraw->handle($model, (string) $user->getAuthIdentifier());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Du stehst nicht mehr auf der Liste.')]);

        return back();
    }

    /**
     * Brand-scoped by the ambient `BrandScope` on the brand host, so a
     * competition of another brand is simply not there — a 404, and no
     * answer about whether it exists.
     */
    private function find(string $competition): Competition
    {
        /** @var Competition|null $model */
        $model = Competition::query()->whereKey($competition)->first();
        abort_if($model === null, 404);

        return $model;
    }
}

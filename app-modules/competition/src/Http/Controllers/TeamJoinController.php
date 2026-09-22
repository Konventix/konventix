<?php

declare(strict_types=1);

namespace Konventix\Competition\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Competition\Actions\JoinTeamByCode;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Platform\Brands\BrandContext;

/**
 * Join by invite code (design §5.2, §10's `/my/tournaments/join`) — the only
 * self-service way onto a roster.
 *
 * The form accepts a prefilled `code` query parameter so a captain can paste
 * a whole link into Discord rather than a code people have to retype. The
 * code is normalized before it is looked up, because a code typed from a
 * phone arrives lower case, with spaces, and sometimes with a zero where the
 * alphabet only ever had an `O`.
 *
 * **Every refusal reads the same to the client**: "dieser Code passt nicht".
 * A distinct "that code exists but you are not registered for this event"
 * would turn the form into an oracle for guessing codes, and the person who
 * genuinely holds no registration is told what to do about it by the page
 * they came from, not by this one.
 *
 * Copy is German (ADR-0001).
 */
final class TeamJoinController
{
    public function show(Request $request): Response
    {
        return Inertia::render('competition/my/Join', [
            'code' => CompetitionTeam::normalizeInviteCode((string) $request->query('code', '')),
        ]);
    }

    public function store(Request $request, JoinTeamByCode $join): RedirectResponse
    {
        $validated = $request->validate([
            // Long enough for a code typed with spaces or dashes; the Action
            // normalizes it down to eight characters before looking it up.
            'code' => ['required', 'string', 'max:32'],
        ]);

        $user = $request->user();
        abort_if($user === null, 403);

        try {
            $join->handle(
                (string) app(BrandContext::class)->currentOrFail()->getKey(),
                $validated['code'],
                (string) $user->getAuthIdentifier(),
            );
        } catch (CompetitionEntryException) {
            return back()->withErrors([
                'code' => __('Dieser Code passt nicht — er ist unbekannt, das Team ist voll, du bist schon dabei, '
                    .'oder du bist für diese Veranstaltung nicht angemeldet.'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Du bist jetzt im Team.')]);

        return to_route('competition.my.tournaments');
    }
}

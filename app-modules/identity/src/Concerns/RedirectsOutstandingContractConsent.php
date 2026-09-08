<?php

declare(strict_types=1);

namespace Konventix\Identity\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Konventix\Compliance\Support\OutstandingConsents;
use Laravel\Fortify\Fortify;

/**
 * The post-login half of the consent gate (GitLab #205, operator decision of
 * 08.09.2026: a contract text is asked for at login and at checkout).
 *
 * There is no route to hang `require-consent` on here — the moment worth
 * interrupting is the redirect out of the login POST itself, which is why
 * this lives in Fortify's two login responses instead. Both of them normally
 * end in `redirect()->intended()`, and `intended()` *consumes*
 * `url.intended`; a gate that let that happen would land the person on the
 * consent screen and then have nowhere to send them afterwards. So the
 * destination is written back (defaulting to Fortify's own post-login home)
 * and `ConsentController::store()`'s own `redirect()->intended()` spends it.
 *
 * Only `LawfulBasis::Contract` texts interrupt a login: an informational or
 * consent-based text is what the `require-consent` route gate is for, and
 * standing between someone and their own account over one would make every
 * new photo-consent version a lockout.
 *
 * A JSON login (Fortify's headless path) is never redirected — the caller
 * gets its usual body, and the gate catches it on the first gated route or
 * at checkout instead.
 */
trait RedirectsOutstandingContractConsent
{
    /**
     * The consent screen when this login still owes agreement to a contract
     * text, null when it does not and the caller should answer as usual.
     */
    private function outstandingContractRedirect(Request $request): ?RedirectResponse
    {
        if ($request->wantsJson()) {
            return null;
        }

        $user = $request->user();

        if ($user === null) {
            return null;
        }

        // Resolved at call time: `OutstandingConsents` reads the *scoped*
        // BrandContext, and these responses are container-resolved once per
        // request but registered from a provider (.ai/rules/providers.md).
        if (! app(OutstandingConsents::class)->subjectHasOutstandingContract((string) $user->getAuthIdentifier())) {
            return null;
        }

        $request->session()->put(
            'url.intended',
            $request->session()->get('url.intended', Fortify::redirects('login')),
        );

        return redirect()->route('policies.required');
    }
}

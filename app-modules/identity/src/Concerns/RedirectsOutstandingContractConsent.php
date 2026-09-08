<?php

declare(strict_types=1);

namespace Konventix\Identity\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;

/**
 * The post-login half of the contract-consent gate (GitLab #205, operator
 * decision of 08.09.2026: a contract text is asked for at login and at
 * checkout).
 *
 * There is no route to hang `require-consent` on here — the moment worth
 * interrupting is the redirect out of the login POST itself, which is why
 * this sits in Fortify's two login responses (and in `SocialAuthController`,
 * whose sign-in never reaches them).
 *
 * Identity does not ask the question and may not: only Compliance sees a
 * policy row, and ADR-0004 lets the two modules exchange scalars and nothing
 * else. Compliance listens for Laravel's `Login` event, works out whether
 * this account still owes agreement to a contract text, and leaves exactly
 * one string behind:
 *
 *     session('compliance.consent_redirect')  // a root-relative URL, or absent
 *
 * That key is the whole contract, written by
 * `Konventix\Compliance\Listeners\FlagOutstandingContractConsent` and spelled
 * out here by hand rather than imported — importing the constant would be
 * the cross-module dependency the architecture test forbids. Both sides
 * carry this comment, because a session key nobody documents is a coupling
 * nobody can find.
 *
 * The redirect **writes `url.intended` back** rather than letting
 * `redirect()->intended()` consume it — defaulting to Fortify's own
 * post-login home when there was none — so `ConsentController::store()`'s own
 * `redirect()->intended()` lands the person where they were going.
 *
 * A JSON login (Fortify's headless path) is never redirected: the caller
 * gets its usual body, and the gate catches it on the first gated route or
 * at checkout instead.
 */
trait RedirectsOutstandingContractConsent
{
    /**
     * The scalar Compliance leaves behind. Kept in step with
     * `FlagOutstandingContractConsent::SESSION_KEY` by name, never by import.
     */
    private const string CONSENT_REDIRECT_SESSION_KEY = 'compliance.consent_redirect';

    /**
     * The consent screen when this login still owes agreement to a contract
     * text, null when it does not and the caller should answer as usual.
     */
    private function outstandingContractRedirect(Request $request): ?RedirectResponse
    {
        if ($request->wantsJson() || ! $request->hasSession()) {
            return null;
        }

        $target = $request->session()->pull(self::CONSENT_REDIRECT_SESSION_KEY);

        if (! is_string($target) || $target === '') {
            return null;
        }

        $request->session()->put(
            'url.intended',
            $request->session()->get('url.intended', Fortify::redirects('login')),
        );

        return redirect()->to($target);
    }
}

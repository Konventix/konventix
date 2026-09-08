<?php

declare(strict_types=1);

namespace Konventix\Compliance\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Konventix\Compliance\Support\OutstandingConsents;

/**
 * The login half of the contract-consent gate (GitLab #205, operator
 * decision of 08.09.2026), computed here because only Compliance may look
 * at a policy row.
 *
 * Identity owns the login *response*; it may not own the *question*.
 * ADR-0004 lets modules exchange scalars and nothing else, so this listener
 * answers on Laravel's own `Login` event — the one moment every way in
 * passes through, password, two-factor challenge and social sign-in alike —
 * and leaves a single string behind:
 *
 *     session('compliance.consent_redirect')  // a root-relative URL, or absent
 *
 * That key is the whole contract. Identity's login responses read it by
 * name, redirect there, and never learn that a policy exists; Compliance
 * never learns that a `User` model does. Both sides carry the same comment,
 * because a session key nobody documents is a coupling nobody can find.
 *
 * The key is cleared before it is set, so an account that has since agreed
 * — or a `Login` fired by a remembered-cookie re-authentication — leaves no
 * stale redirect behind for the next response to follow.
 *
 * Only `LawfulBasis::Contract` raises it. A consent-based required text is
 * what the `require-consent` route alias is for, and standing between
 * someone and their own account over one would make every new photo-consent
 * version a lockout.
 */
final class FlagOutstandingContractConsent
{
    /**
     * The one scalar that crosses the Compliance ↔ Identity boundary.
     * Identity spells it out by hand rather than importing this constant —
     * an import is exactly what ADR-0004 forbids.
     */
    public const string SESSION_KEY = 'compliance.consent_redirect';

    public function handle(Login $event): void
    {
        // Resolved at call time, never constructor-injected: the dispatcher
        // builds listeners with the container it was constructed with, which
        // under Octane is the worker's rather than the request's
        // (.ai/rules/listeners.md).
        $request = app(Request::class);

        if (! $request->hasSession()) {
            return;
        }

        $request->session()->forget(self::SESSION_KEY);

        if (! app(OutstandingConsents::class)->subjectHasOutstandingContract((string) $event->user->getAuthIdentifier())) {
            return;
        }

        // Root-relative: a brand answers on several domains (ADR-0001), and
        // a redirect bound to whichever host served the login would move the
        // person off the one they signed in on.
        $request->session()->put(self::SESSION_KEY, route('policies.required', absolute: false));
    }
}

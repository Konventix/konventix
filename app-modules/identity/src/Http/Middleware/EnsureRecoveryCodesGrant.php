<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes Fortify's `GET two-factor.recovery-codes` a one-shot (D-IDP-18,
 * round 4 Task 26).
 *
 * The vendor controller hands the full decrypted set to anyone who asks,
 * every time they ask, for as long as the session lives — so a borrowed
 * browser, a forgotten session or an XSS-shaped bug reads the codes back at
 * will, and the "I saved my recovery codes" checkbox in front of the dialog
 * was the only thing standing there. The checkbox stays a nudge; this is the
 * boundary.
 *
 * A reveal needs a grant, minted into the session by
 * `Listeners\MintRecoveryCodesGrant` at the two moments a fresh set exists:
 * `TwoFactorAuthenticationConfirmed` and `RecoveryCodesGenerated`. The grant
 * is `pull()`ed, not read — the first request consumes it, so a reload, a
 * second tab or a replay of the same URL finds nothing. It also names the
 * account it was minted for and expires after 60 seconds, which is the gap
 * between the redirect and the page's own `fetch()`, not a browsing session.
 *
 * 404 rather than 403, for the reason `PasskeyController::destroy()` says
 * 404: "you may not" on an endpoint that exists tells the caller the codes
 * are there to be had. The route is simply not there without a grant.
 *
 * Constructor-less on purpose (`EnsureSignupsOpen`'s shape otherwise): the
 * session and the user are request state and are resolved inside `handle()`.
 * `IdentityServiceProvider::throttleUnhookedFortifyRoutes()` wraps it onto
 * the GET route after the fact; the POST that regenerates keeps answering
 * without a grant, because asking for a new set is how a person who lost the
 * old one gets codes again.
 */
final class EnsureRecoveryCodesGrant
{
    /**
     * How long a minted grant is good for. Long enough for the redirect and
     * the page's fetch, short enough that a grant left behind by an
     * abandoned tab is worthless.
     */
    private const TTL_SECONDS = 60;

    private const KEY = 'two-factor.recovery-codes.reveal';

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->hasSession(), 404);

        $grant = $request->session()->pull(self::KEY);
        $user = $request->user();

        abort_unless(is_array($grant) && $user !== null, 404);

        $account = $grant['user'] ?? null;
        $mintedAt = $grant['at'] ?? null;

        abort_unless(
            is_scalar($account) && (string) $account === (string) $user->getAuthIdentifier(),
            404,
        );

        abort_unless(
            is_int($mintedAt) && $mintedAt <= time() && $mintedAt >= time() - self::TTL_SECONDS,
            404,
        );

        return $next($request);
    }
}

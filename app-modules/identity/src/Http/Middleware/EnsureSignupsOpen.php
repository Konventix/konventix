<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Konventix\Platform\Operators\OperatorSettings;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a stranger away from account creation while signups are closed
 * (hotfix 2026-09-13).
 *
 * One gate for both ways in: Fortify's `register` and `register.store` get
 * it wrapped on after the fact (`IdentityServiceProvider::
 * throttleUnhookedFortifyRoutes()`, the same mechanism as the limiters), the
 * social completion routes carry it by name (`signups-open`). Whether they
 * are open is `OperatorSettings::signupsOpen()`'s answer — the instance cap
 * from the environment and the operator's own switch, combined in one place.
 *
 * A redirect to the login page with a message, not a 403: the person who
 * lands here followed a link that used to work, and "sign in instead" is
 * the one useful thing to tell them. `withErrors` on `email` because that is
 * the field the login page already renders errors under — a status flash
 * would read as good news in green.
 */
final class EnsureSignupsOpen
{
    public function __construct(private readonly OperatorSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->settings->signupsOpen()) {
            return redirect()->route('login')
                ->withErrors(['email' => __('Sign-up is closed at the moment. If you already have an account, log in here.')]);
        }

        return $next($request);
    }
}

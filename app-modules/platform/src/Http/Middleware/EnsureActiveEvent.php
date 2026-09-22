<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Konventix\Platform\Hub\ActiveEventContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * The live hub's gate (design §7.1): `/live` exists only for a member who is
 * admitted to a running event on this brand. Anyone else is sent home with
 * a toast rather than a 404 — the page is linked from the topbar pill, so
 * reaching it without access means the access ended, and "nothing here" is
 * the wrong answer to that.
 *
 * Registered as the `ensure-active-event` route middleware alias, beside
 * `ensure-module` and `ensure-feature`. Runs after `auth`, so a guest is
 * redirected to login before this asks the context anything.
 *
 * The context is resolved inside `handle()`: it is a scoped binding, and
 * middleware instances are built per request anyway, but the pattern is the
 * same one every Platform singleton follows (`.ai/rules/providers.md`).
 */
final class EnsureActiveEvent
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(ActiveEventContext::class)->current() === null) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'Kein laufendes Event, für das du eingecheckt bist.']);

            return redirect()->to('/');
        }

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Compliance\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Konventix\Compliance\Support\OutstandingConsents;
use Symfony\Component\HttpFoundation\Response;

/**
 * The consent gate, as an alias (`require-consent`) other modules apply to
 * the routes that legally need it — Registration's public form is the first
 * consumer.
 *
 * Opt-in rather than global on purpose. Compliance registers the alias from
 * its own provider and wires nothing centrally (ADR-0003); the module that
 * knows a route may not be entered without agreement is the module that owns
 * the route. A blanket gate on the whole `web` group would also make the
 * policy page itself, the login page and every error page a redirect
 * candidate, which is how consent walls turn into redirect loops.
 *
 * Guests pass through untouched: consent belongs to a subject, and an
 * anonymous visitor has none to check. Routes that need both put `auth`
 * first — this middleware refuses to guess whether an unauthenticated
 * request should be logged in or blocked.
 *
 * A blocked request is redirected to `/policies/required`, with its own URL
 * remembered so agreeing lands the person exactly where they were going.
 * Only GET requests set that intent — replaying a POST after a redirect is
 * not something a consent screen may decide to do.
 */
final class EnsurePoliciesAccepted
{
    public function __construct(private readonly OutstandingConsents $outstanding) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if (! $this->outstanding->subjectHasOutstanding((string) $user->getAuthIdentifier())) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('policies.required');
    }
}

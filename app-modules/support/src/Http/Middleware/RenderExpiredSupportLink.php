<?php

declare(strict_types=1);

namespace Konventix\Support\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Konventix\Support\Actions\ReissueSupportLink;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a guest gets when their 30-day link has run out, or has been
 * truncated by a mail client, or has been retyped by hand: a page that
 * offers them a new one (design §8.2).
 *
 * The alternative — Laravel's bare 403 "Invalid signature" — is the worst
 * possible answer here. The person holding that URL is not an attacker
 * probing a signature; they are somebody who reported that the checkout
 * was broken five weeks ago and clicked the mail again, and a 403 tells
 * them their own report is none of their business. So the failure is
 * caught and rendered as a screen with one button.
 *
 * It asks the same question `signed` asks — `hasValidSignature()`, absolute,
 * the identical call `ValidateSignature` makes — and answers a "no" with a
 * page instead of an exception. It does **not** replace `signed`, which
 * still stands behind it on every one of these routes: if this check ever
 * drifts from that one, the route refuses rather than opening.
 *
 * Catching the exception instead was the obvious shape and does not work.
 * `Illuminate\Routing\Pipeline` wraps *every* pipe in its own try/catch and
 * hands a `Throwable` straight to the exception handler, so an outer
 * middleware never sees `InvalidSignatureException` at all — it sees a
 * finished 403 response. That is worth stating here, because the code that
 * tried it reads as though it should work.
 *
 * The page carries the reference derived from the id in the URL and
 * nothing else. It never looks the report up, so it cannot say whether
 * that reference exists — the same refusal to be an oracle that
 * {@see ReissueSupportLink} makes on the other
 * side of the button.
 *
 * **Status 200, deliberately.** An Inertia page is the answer here rather
 * than an error, and both entry points need the client to render it: the
 * GET from the mail, and a POST from a page whose link expired while the
 * form stood open. A 4xx would have Inertia's client treat the rendered
 * page as a transport failure and show a modal instead.
 */
final class RenderExpiredSupportLink
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasValidSignature()) {
            return $next($request);
        }

        $id = $request->route('supportRequest');

        return Inertia::render('support/requests/LinkExpired', [
            'reference' => is_string($id) && $id !== ''
                ? Str::upper(Str::substr($id, -8))
                : null,
        ])->toResponse($request);
    }
}

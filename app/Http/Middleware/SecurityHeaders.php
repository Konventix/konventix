<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the application's own security headers on every response (#251).
 *
 * Global rather than `web`, because the surfaces that need these most are the
 * ones outside that group: the unauthenticated media route (#252), the three
 * PSP webhooks and the health endpoints. A header set by the ingress
 * controller is not the application's — a change of ingress class takes it
 * away silently, and nothing in this repository notices (audit N1).
 *
 * The boundary is PHP: everything the web server answers by itself —
 * `public/` with the Vite bundle in it, and `public/storage/*` for a
 * `public_direct` media disk (#252) — never enters this pipeline and carries
 * none of these headers. Closing that needs a Caddyfile the repository does
 * not have yet, and is its own piece of work.
 *
 * What is deliberately NOT here is a `script-src`. `resources/views/app.blade.php`
 * renders two inline blocks — the appearance probe that runs before the CSS
 * loads, and the brand token `<style>` — and Vite serves its dev client from a
 * second origin, so a script policy needs per-request nonces threaded through
 * Blade, Inertia and the Vite tag helper. That is its own piece of work; the
 * directives below need none of it and already close the framing, plugin and
 * base-URI holes.
 */
final class SecurityHeaders
{
    /**
     * Framing is forbidden outright rather than same-origin: nothing in this
     * application frames a route of its own. The two `<iframe>` elements in
     * the console render brand-controlled HTML through `srcdoc` with an empty
     * `sandbox`, which no ancestor policy touches. `X-Frame-Options` rides
     * along for browsers that still read it first.
     *
     * `object-src 'none'` kills the plugin-embedding path that `nosniff`
     * alone does not, and `base-uri 'self'` stops an injected `<base>` from
     * re-pointing every relative URL on the page.
     */
    private const CONTENT_SECURITY_POLICY = "frame-ancestors 'none'; object-src 'none'; base-uri 'self'";

    /**
     * Every powerful feature this application never asks for. `payment=()` is
     * safe because the settlement flow leaves the origin entirely: the PSP's
     * hosted page is reached with `Inertia::location()` — a full navigation,
     * not an embedded frame (`CartController::checkout()`).
     */
    private const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=()';

    /**
     * One year, subdomains included, no `preload` — preloading is a decision
     * about a whole registrable domain and belongs to whoever owns it, not to
     * a deployment of this application.
     */
    private const STRICT_TRANSPORT_SECURITY = 'max-age=31536000; includeSubDomains';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Permissions-Policy', self::PERMISSIONS_POLICY);
        $response->headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);

        // Only over TLS: a browser handed HSTS on a plain HTTP response is
        // being told to pin a scheme the local development stack does not
        // serve. `isSecure()` reads `X-Forwarded-Proto` from a trusted proxy
        // (config/trustedproxy.php, #258), which is exactly the truth wanted.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', self::STRICT_TRANSPORT_SECURITY);
        }

        return $response;
    }
}

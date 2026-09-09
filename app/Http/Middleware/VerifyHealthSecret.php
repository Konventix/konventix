<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the machine-readable health report with a shared secret.
 *
 * A 404 rather than a 401, deliberately, and for the same reason admin routes
 * 404 on brand hosts (ADR-0029): a caller without the secret should not learn
 * that a health endpoint exists here at all.
 *
 * `hash_equals` rather than `===` — the comparison is against a secret, and a
 * timing-distinguishable one is a secret that can be guessed a byte at a time.
 *
 * Since ADR-0059 the same secret also opens `/metrics`, as header or as bearer
 * token.
 */
class VerifyHealthSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('health.endpoint_secret', '');

        abort_if($expected === '', 404);
        abort_unless($this->presented($request, $expected), 404);

        return $next($request);
    }

    /**
     * The health header is what an operator's monitor sends; the bearer form
     * is what vmagent sends for `/metrics` (ADR-0059). Both compare with
     * `hash_equals` for the same reason as before.
     */
    private function presented(Request $request, string $expected): bool
    {
        $header = (string) $request->header('X-Health-Secret');

        if ($header !== '' && hash_equals($expected, $header)) {
            return true;
        }

        $bearer = (string) $request->bearerToken();

        return $bearer !== '' && hash_equals($expected, $bearer);
    }
}

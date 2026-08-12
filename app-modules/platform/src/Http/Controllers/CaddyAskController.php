<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Konventix\Platform\Models\BrandDomain;
use Konventix\Platform\Models\Operator;

/**
 * Caddy's on-demand TLS "ask" endpoint (ADR-0001): before minting a
 * certificate for an unknown host, Caddy asks whether any brand answers on it.
 * 200 issues the certificate, 404 refuses — which is what stops the operator's
 * server from being a certificate mint for arbitrary domains pointed at it.
 *
 * Deliberately outside the `web` group: the host being asked about is by
 * definition not resolvable to a brand yet, so ResolveBrand must not run, and
 * neither sessions nor cookies have any business here.
 */
class CaddyAskController
{
    public function __invoke(Request $request): Response
    {
        $domain = $request->query('domain');

        abort_unless(is_string($domain) && $domain !== '', 404);

        $host = mb_strtolower($domain);

        // Brand hosts and the operator's admin host (ADR-0029) both deserve
        // certificates; everything else is refused.
        $known = BrandDomain::query()->where('host', $host)->exists()
            || Operator::query()->where('admin_host', $host)->exists();

        abort_unless($known, 404);

        return response()->noContent(200);
    }
}

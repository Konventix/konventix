<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The WebAuthn relying party is the request host (bugfix round 2026-09-21,
 * Task 16, stated assumption).
 *
 * `laravel/passkeys` reads `passkeys.relying_party_id` and
 * `passkeys.allowed_origins` from config on every ceremony, and Fortify fills
 * them once at boot from `app.url`. One instance answers on every brand's
 * domain and on the operator console (ADR-0001, ADR-0029), so a single
 * boot-time value would either reject every host but one or — worse — hand
 * out challenges for a relying party the browser is not on. Instead the two
 * keys follow the host of the request, the way `ResolveBrand` re-sets the
 * session cookie name per brand: a passkey is created for, and only ever
 * offered on, the domain the person was on. A passkey that works across
 * brands would need one shared registrable domain and is out of scope.
 *
 * Applied to Fortify's seven passkey routes from `IdentityServiceProvider`'s
 * `booted()` route walk, not to the `web` group: nothing else reads these
 * keys. Config, not a singleton: Octane clones the config repository for
 * each request (`CreateConfigurationSandbox`), so the override is gone with
 * the request and no worker remembers the previous host.
 */
final class BindPasskeyRelyingParty
{
    public function __construct(private readonly ConfigRepository $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->config->set('passkeys.relying_party_id', $request->getHost());
        $this->config->set('passkeys.allowed_origins', [$request->getSchemeAndHttpHost()]);

        return $next($request);
    }
}

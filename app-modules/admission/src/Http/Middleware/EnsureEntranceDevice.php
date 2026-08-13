<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Konventix\Admission\Models\EntranceDevice;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a scanner against the entrance API by its own bearer token
 * (ADR-0033), replacing LanCore's single shared integration token which had
 * no per-device identity and no revocation path.
 *
 * The device is resolved with `withoutBrandScope()` because these requests
 * arrive at an API host with no brand resolved — the *device* determines the
 * brand, not the hostname. Everything downstream then scopes explicitly to
 * `$device->brand_id`, which is why nothing here sets ambient context.
 *
 * A revoked device is rejected with the same 401 as an unknown one: telling a
 * caller "this token existed but was revoked" is information a lost scanner's
 * finder has no business getting.
 */
final class EnsureEntranceDevice
{
    public const string ATTRIBUTE = 'entrance_device';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return $this->unauthenticated();
        }

        $device = EntranceDevice::withoutBrandScope()
            ->where('token_hash', EntranceDevice::hashToken($token))
            ->first();

        if ($device === null || $device->isRevoked()) {
            return $this->unauthenticated();
        }

        // Liveness for the operator console: how an operator sees that a lane
        // has gone quiet. Written without touching updated_at, so it does not
        // masquerade as a configuration change.
        $device->forceFill(['last_seen_at' => now()])->saveQuietly();

        $request->attributes->set(self::ATTRIBUTE, $device);

        return $next($request);
    }

    private function unauthenticated(): Response
    {
        return response()->json([
            'message' => 'Unknown or revoked entrance device.',
        ], 401);
    }
}

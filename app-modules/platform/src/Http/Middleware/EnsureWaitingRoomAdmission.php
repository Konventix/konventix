<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Konventix\Platform\WaitingRoom\WaitingRoomGate;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0047 point 5: registered as the `ensure-waiting-room` route
 * middleware alias — a route names it the same way it names
 * `ensure-feature:commerce:waiting-room`... except this one names no
 * feature at all: Commerce's `CommerceWaitingRoomGate` does its own
 * `commerce:waiting-room` check internally, so a module standing behind
 * the door (Commerce's shop and cart, Registration's public form) applies
 * this alias without importing Commerce or naming its feature string.
 *
 * `WaitingRoomGate` is resolved from the container lazily, not
 * constructor-injected: Commerce binds it unconditionally in the real
 * application, but this middleware must still degrade to a harmless
 * pass-through if nothing is ever bound — the module standing behind the
 * door does not get to assume Commerce loaded first.
 */
class EnsureWaitingRoomAdmission
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = app()->bound(WaitingRoomGate::class)
            ? app(WaitingRoomGate::class)->guard($request)
            : null;

        return $response ?? $next($request);
    }
}

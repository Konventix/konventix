<?php

declare(strict_types=1);

namespace Konventix\Resources\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Konventix\Resources\Actions\ComputePlanAvailability;
use Konventix\Resources\Actions\FindSeatClaimForRegistrant;

/**
 * The picker's 15-second poll (design §7.4): plain JSON, the same
 * `PlanAvailabilityData` the page was rendered with, behind the same
 * ownership check. Not an Inertia partial reload — the page keeps the
 * person's selection in local state and only wants the map, and a JSON
 * endpoint is what `useHttp` is for (Inertia v3). Same shape as
 * `WaitingRoomController::status()`.
 */
final class SeatAvailabilityController
{
    public function show(
        Request $request,
        string $registration,
        FindSeatClaimForRegistrant $claims,
        ComputePlanAvailability $availability,
    ): JsonResponse {
        $claim = $claims->handle($registration, (string) $request->user()->getAuthIdentifier());
        abort_if($claim === null, 404);

        return response()->json($availability->handle($claim));
    }
}

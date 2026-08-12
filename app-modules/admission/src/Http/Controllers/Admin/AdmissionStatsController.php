<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Enums\Permission;
use Konventix\Admission\Models\Admission;

/**
 * Operator console: per-event admission numbers (ADR-0029), consumed by the
 * `admission/EventStatsCard` component the Event admin dashboard renders
 * through its `event.admin.dashboard` UI slot. Event never queries this
 * module's models directly — it only knows the slot's component name and the
 * event ULID; this endpoint is how the card gets its own data.
 *
 * `Admission` is brand-owned (ADR-0010); the admin host hardens `BrandScope`
 * to nothing (ADR-0029), so this uses the explicit `withoutBrandScope()`
 * escape hatch deliberately — the event ULID in the route already implies
 * which brand these rows belong to.
 */
final class AdmissionStatsController
{
    public function __invoke(string $event): JsonResponse
    {
        Gate::authorize(Permission::ViewAdmissions->value);

        $admissions = Admission::withoutBrandScope()
            ->where('event_id', $event)
            ->get(['state']);

        $counts = collect(AdmissionState::cases())
            ->mapWithKeys(fn (AdmissionState $state): array => [
                $state->value => $admissions->where('state', $state)->count(),
            ]);

        return response()->json([
            'counts' => $counts,
            'total' => $admissions->count(),
        ]);
    }
}

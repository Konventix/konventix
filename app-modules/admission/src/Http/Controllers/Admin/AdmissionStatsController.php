<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Enums\Permission;
use Konventix\Admission\Http\Controllers\Admin\Concerns\ScopesToOperator;
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
 * escape hatch deliberately. That escape is also why the event is held to
 * the admin host's own operator first (`ScopesToOperator`): nothing else
 * stops a console account of another operator that knows the event ULID from
 * reading its numbers, so such a request reads 404 (#329 final review I2), as
 * does an event this module has no summary of. The counts then read by the
 * summary's brand as well as the event.
 */
final class AdmissionStatsController
{
    use ScopesToOperator;

    public function __invoke(string $event): JsonResponse
    {
        Gate::authorize(Permission::ViewAdmissions->value);

        $summary = $this->findEventSummary($event);

        $admissions = Admission::withoutBrandScope()
            ->where('brand_id', $summary->brand_id)
            ->where('event_id', $summary->event_id)
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

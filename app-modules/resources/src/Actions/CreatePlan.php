<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Resources\Data\PlanData;
use Konventix\Resources\Data\PlanDocumentData;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\ResourceEventWindow;

/**
 * An empty plan (design §4.4) from the console's create dialog: the header
 * only, an empty `konventix.plan/v1` document at version 0, no places.
 * Everything after that goes through `SavePlan` (§6.1), which is why this
 * Action writes no version row and emits nothing — there is no document
 * to version yet.
 *
 * `venue_id` stays null in v1 (§4.4: no venue picker). The event must be
 * one this module knows for the brand — the window projection is the only
 * event fact Resources may read (ADR-0004) — and the name is unique per
 * brand and event (§4.4), refused here so the operator sees a field error
 * rather than a constraint violation.
 */
final class CreatePlan
{
    public function handle(string $brandId, PlanData $data, ?string $actorUserId): Plan
    {
        return DB::transaction(function () use ($brandId, $data, $actorUserId): Plan {
            if (ResourceEventWindow::forEvent($brandId, $data->eventId) === null) {
                throw ValidationException::withMessages([
                    'eventId' => __('Resources does not know this event for the brand yet.'),
                ]);
            }

            $taken = Plan::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('event_id', $data->eventId)
                ->where('name', $data->name)
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    'name' => __('A plan with this name already exists for the event.'),
                ]);
            }

            $plan = new Plan;
            $plan->forceFill([
                'brand_id' => $brandId,
                'event_id' => $data->eventId,
                'venue_id' => null,
                'name' => $data->name,
                'floor_label' => $data->floorLabel,
                'width_mm' => $data->widthMm,
                'height_mm' => $data->heightMm,
                'background' => null,
                'unit_display' => $data->unitDisplay,
                'snap_step' => $data->snapStep,
                'version' => 0,
                'document' => PlanDocumentData::from(['schema' => 'konventix.plan/v1', 'elements' => []]),
                'published_at' => null,
                'origin_id' => null,
                'created_by' => $actorUserId,
            ])->save();

            return $plan;
        });
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Resources\Events\PlanPublished;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\Resource;

/**
 * Makes a plan visible to the picker (design §6.4): sets `published_at` and
 * re-derives `is_published` on the plan's live places, because that flag is
 * the projection's copy of the plan's state (§4.2) and the picker reads only
 * the projection. Idempotent — publishing a published plan changes nothing
 * and emits nothing. Soft-deleted places stay as they are: history.
 */
final class PublishPlan
{
    public function handle(Plan $plan): Plan
    {
        return DB::transaction(function () use ($plan): Plan {
            Plan::withoutBrandScope()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $plan->refresh();

            if ($plan->published_at !== null) {
                return $plan;
            }

            $plan->forceFill(['published_at' => now()])->save();

            Resource::withoutBrandScope()
                ->where('plan_id', $plan->id)
                ->update(['is_published' => true]);

            DB::afterCommit(static function () use ($plan): void {
                event(new PlanPublished(planId: $plan->id, eventId: $plan->event_id, brandId: $plan->brand_id));
            });

            return $plan;
        });
    }
}

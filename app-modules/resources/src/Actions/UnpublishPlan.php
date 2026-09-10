<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Resources\Events\PlanUnpublished;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\Resource;

/**
 * Withdraws a plan from the picker (design §6.4): clears `published_at` and
 * turns `is_published` off on the plan's live places, so the availability
 * read model reports them `unavailable` and `AssignPlacesToRegistration`
 * refuses them. Existing bookings are untouched — unpublishing is not a
 * release. Idempotent, emits only on a change.
 */
final class UnpublishPlan
{
    public function handle(Plan $plan): Plan
    {
        return DB::transaction(function () use ($plan): Plan {
            Plan::withoutBrandScope()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $plan->refresh();

            if ($plan->published_at === null) {
                return $plan;
            }

            $plan->forceFill(['published_at' => null])->save();

            Resource::withoutBrandScope()
                ->where('plan_id', $plan->id)
                ->update(['is_published' => false]);

            DB::afterCommit(static function () use ($plan): void {
                event(new PlanUnpublished(planId: $plan->id, eventId: $plan->event_id, brandId: $plan->brand_id));
            });

            return $plan;
        });
    }
}

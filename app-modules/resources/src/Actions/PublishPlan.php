<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Resources\Events\PlaceSupplyChanged;
use Konventix\Resources\Events\PlanPublished;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\Resource;

/**
 * Makes a plan visible to the picker (design §6.4): sets `published_at` and
 * re-derives `is_published` on the plan's live places, because that flag is
 * the projection's copy of the plan's state (§4.2) and the picker reads only
 * the projection. Idempotent — publishing a published plan changes nothing
 * and emits nothing. Soft-deleted places stay as they are: history.
 *
 * Every live, unblocked place under the plan goes from unbookable to
 * bookable in this one step (a blocked place's bookability does not move —
 * it stays unbookable either way), so `PlaceSupplyChanged` fires per
 * category with `direction: released` and the count of such places
 * (design §12b/#282).
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

            $counts = Resource::withoutBrandScope()
                ->where('plan_id', $plan->id)
                ->where('blocked', false)
                ->selectRaw('resource_type_id, count(*) as aggregate')
                ->groupBy('resource_type_id')
                ->pluck('aggregate', 'resource_type_id');

            Resource::withoutBrandScope()
                ->where('plan_id', $plan->id)
                ->update(['is_published' => true]);

            // Idiom copied from `ReleaseResourceBooking`: whatever brand is
            // ambient now is captured and restored around the deferred
            // dispatch alone, since a console request usually has none.
            $brand = app(BrandContext::class)->current();

            DB::afterCommit(function () use ($plan, $counts, $brand): void {
                $dispatch = function () use ($plan, $counts): void {
                    event(new PlanPublished(planId: $plan->id, eventId: $plan->event_id, brandId: $plan->brand_id));

                    foreach ($counts as $resourceTypeId => $places) {
                        event(new PlaceSupplyChanged(
                            changeId: (string) Str::ulid(),
                            brandId: $plan->brand_id,
                            eventId: $plan->event_id,
                            resourceTypeId: $resourceTypeId,
                            reason: 'published',
                            direction: 'released',
                            places: (int) $places,
                        ));
                    }
                };

                if ($brand === null) {
                    $dispatch();

                    return;
                }

                app(BrandContext::class)->withBrand($brand, $dispatch);
            });

            return $plan;
        });
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Resources\Events\PlaceSupplyChanged;
use Konventix\Resources\Events\PlanUnpublished;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\Resource;

/**
 * Withdraws a plan from the picker (design §6.4): clears `published_at` and
 * turns `is_published` off on the plan's live places, so the availability
 * read model reports them `unavailable` and `AssignPlacesToRegistration`
 * refuses them. Existing bookings are untouched — unpublishing is not a
 * release. Idempotent, emits only on a change.
 *
 * Every live, unblocked place under the plan goes from bookable to
 * unbookable in this one step (a blocked place already was unbookable), so
 * `PlaceSupplyChanged` fires per category with `direction: consumed` and
 * the count of such places (design §12b/#282).
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

            $counts = Resource::withoutBrandScope()
                ->where('plan_id', $plan->id)
                ->where('blocked', false)
                ->selectRaw('resource_type_id, count(*) as aggregate')
                ->groupBy('resource_type_id')
                ->pluck('aggregate', 'resource_type_id');

            Resource::withoutBrandScope()
                ->where('plan_id', $plan->id)
                ->update(['is_published' => false]);

            // Idiom copied from `ReleaseResourceBooking`: whatever brand is
            // ambient now is captured and restored around the deferred
            // dispatch alone, since a console request usually has none.
            $brand = app(BrandContext::class)->current();

            DB::afterCommit(function () use ($plan, $counts, $brand): void {
                $dispatch = function () use ($plan, $counts): void {
                    event(new PlanUnpublished(planId: $plan->id, eventId: $plan->event_id, brandId: $plan->brand_id));

                    foreach ($counts as $resourceTypeId => $places) {
                        event(new PlaceSupplyChanged(
                            changeId: (string) Str::ulid(),
                            brandId: $plan->brand_id,
                            eventId: $plan->event_id,
                            resourceTypeId: $resourceTypeId,
                            reason: 'unpublished',
                            direction: 'consumed',
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

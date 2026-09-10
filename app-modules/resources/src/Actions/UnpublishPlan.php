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
 *
 * Deliberately does not touch a linked quota (design §12b/#282): publish
 * and unpublish each snapshot the plan's *current* unblocked count at one
 * instant, and the two snapshots need not match — a place added, blocked
 * or unblocked while the plan sat unpublished leaves the mismatch stranded
 * permanently, and the ordinary "draft the plan, size the quota, then sell
 * before publishing" sequence would zero out every sale already recorded
 * the moment the plan opens. An unpublished plan does not stop the
 * category selling: `SubmitRegistration` still consumes the same quota row
 * a seat category names, registrations still sell and confirm, their seat
 * claims sit `unplaced`, and the picker serves them once the plan is
 * published again. That is the intended workflow, not a gap. Block,
 * unblock, an operator reservation and its release remain the only things
 * that move a linked quota, because each of those is symmetric on its own.
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

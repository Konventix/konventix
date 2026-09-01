<?php

declare(strict_types=1);

namespace Konventix\Community\Listeners;

use Konventix\Community\Models\CommunityMember;
use Konventix\Identity\Events\BrandMembershipProfileUpdated;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Modules\ModuleGate;
use Laravel\Pennant\Feature;

/**
 * Projects Identity's `BrandMembershipProfileUpdated` into Community's
 * per-brand membership read model (SPEC.md "Owns" — community profile).
 * Registered explicitly in `CommunityServiceProvider::registerListeners()` —
 * discovery is off and must stay off (.ai/rules/listeners.md).
 *
 * The `Konventix\Identity\Events\` import is the sanctioned exception:
 * events are the public contract between modules (ADR-0004).
 */
final class ProjectCommunityMember
{
    public function handle(BrandMembershipProfileUpdated $event): void
    {
        $brand = Brand::query()->find($event->brandId);

        // Per-brand gate on the EVENT's brand, not the request's — replays
        // run with no request. A brand that turns Community on later is
        // backfilled by identity:replay-profiles (SPEC "Activation").
        if ($brand === null || ! Feature::for($brand)->active(app(ModuleGate::class)->featureName('community'))) {
            return;
        }

        // withoutBrandScope() because this listener runs off any host, with
        // no ambient brand in context. `brand_id` is NOT mass-assignable
        // (.ai/rules/models.md — BelongsToBrand's invariant is that only the
        // ambient-context `creating` hook or a named escape hatch sets it),
        // so this projection-only writer sets it directly via forceFill()
        // instead of putting it through fill()/mass assignment: the value
        // comes from the event, never from ambient context, and must not
        // become something an ordinary caller's array could override.
        $member = CommunityMember::withoutBrandScope()
            ->where('user_id', $event->userId)
            ->where('brand_id', $event->brandId)
            ->firstOrNew();

        $member->forceFill([
            'user_id' => $event->userId,
            'brand_id' => $event->brandId,
            'avatar_url' => $event->avatarUrl,
            'banner_url' => $event->bannerUrl,
            'member_since' => $event->firstSeenAt,
        ])->save();
    }
}

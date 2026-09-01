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

        // Explicit brand_id bypasses the ambient-context stamp on purpose;
        // withoutBrandScope() because the listener runs off any host.
        CommunityMember::withoutBrandScope()->updateOrCreate(
            ['user_id' => $event->userId, 'brand_id' => $event->brandId],
            [
                'avatar_url' => $event->avatarUrl,
                'banner_url' => $event->bannerUrl,
                'member_since' => $event->firstSeenAt,
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Community\Providers;

use Illuminate\Support\Facades\Event;
use Konventix\Community\Listeners\ProjectCommunityMember;
use Konventix\Community\Listeners\ProjectCommunityProfile;
use Konventix\Identity\Events\BrandMembershipProfileUpdated;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Platform\Modules\ModuleServiceProvider;

/**
 * Registers everything this module owns. Registration is unconditional and
 * happens at boot; whether the module is *active* is a per-brand Pennant gate
 * applied at four points — routes, navigation, UI slots, listeners (ADR-0003).
 *
 * Never move any of this wiring into a central provider.
 */
class CommunityServiceProvider extends ModuleServiceProvider
{
    /**
     * The module's Pennant feature name and route-middleware key.
     */
    public static function moduleName(): string
    {
        return 'community';
    }

    /**
     * The community profile read models (ADR-0004). Registered explicitly —
     * modular event discovery is off and must stay off
     * (.ai/rules/listeners.md). `ProjectCommunityMember` gates itself on the
     * event's brand; `ProjectCommunityProfile` does not, because
     * `community_profiles` is operator-wide (ADR-0016).
     */
    protected function registerListeners(): void
    {
        Event::listen(UserRegistered::class, ProjectCommunityProfile::class);
        Event::listen(UserProfileUpdated::class, ProjectCommunityProfile::class);
        Event::listen(BrandMembershipProfileUpdated::class, ProjectCommunityMember::class);
    }
}

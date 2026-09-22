<?php

declare(strict_types=1);

namespace Konventix\Community\Providers;

use Illuminate\Support\Facades\Event;
use Konventix\Admission\Events\ParticipantAdmitted;
use Konventix\Admission\Events\ParticipantHubAccessEnded;
use Konventix\Admission\Events\ParticipantPresenceChanged;
use Konventix\Community\Gdpr\CommunityGdprHandler;
use Konventix\Community\Listeners\ProjectAdmissionBadge;
use Konventix\Community\Listeners\ProjectCommunityMember;
use Konventix\Community\Listeners\ProjectCommunityProfile;
use Konventix\Community\Listeners\ProjectEventParticipation;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Identity\Events\BrandMembershipProfileUpdated;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationRejected;
use Konventix\Registration\Events\RegistrationSubmitted;

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
     * `ProjectEventParticipation` (ADR-0052 §3) gates itself the same way as
     * `ProjectCommunityMember`, on the Registration event's own brand, and
     * is registered for all four events it consumes.
     * `ProjectAdmissionBadge` (design §4.5, ADR-0067 decision 2) gates
     * itself the same way, on the Admission event's own brand, and is
     * registered for the three participant events it consumes —
     * `ParticipantAdmissionReplaced` deliberately excluded, see its own
     * docblock.
     */
    protected function registerListeners(): void
    {
        Event::listen(UserRegistered::class, ProjectCommunityProfile::class);
        Event::listen(UserProfileUpdated::class, ProjectCommunityProfile::class);
        Event::listen(BrandMembershipProfileUpdated::class, ProjectCommunityMember::class);
        Event::listen(RegistrationSubmitted::class, ProjectEventParticipation::class);
        Event::listen(RegistrationConfirmed::class, ProjectEventParticipation::class);
        Event::listen(RegistrationCancelled::class, ProjectEventParticipation::class);
        Event::listen(RegistrationRejected::class, ProjectEventParticipation::class);
        Event::listen(ParticipantAdmitted::class, ProjectAdmissionBadge::class);
        Event::listen(ParticipantPresenceChanged::class, ProjectAdmissionBadge::class);
        Event::listen(ParticipantHubAccessEnded::class, ProjectAdmissionBadge::class);
    }

    /**
     * The profile-wall shapes Task 9 built and ADR-0052's visibility/event-
     * participation additions — see `CommunityGdprHandler`'s class docblock
     * for the deletion order.
     */
    protected function registerGdprExporters(): void
    {
        $this->app->make(GdprHandlerRegistry::class)->register($this->app->make(CommunityGdprHandler::class));
    }
}

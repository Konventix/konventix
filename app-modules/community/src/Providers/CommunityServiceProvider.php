<?php

declare(strict_types=1);

namespace Konventix\Community\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Konventix\Admission\Events\ParticipantAdmitted;
use Konventix\Admission\Events\ParticipantHubAccessEnded;
use Konventix\Admission\Events\ParticipantPresenceChanged;
use Konventix\Community\Actions\PostMessage;
use Konventix\Community\Gdpr\CommunityGdprHandler;
use Konventix\Community\Listeners\ProjectAdmissionBadge;
use Konventix\Community\Listeners\ProjectCommunityMember;
use Konventix\Community\Listeners\ProjectCommunityProfile;
use Konventix\Community\Listeners\ProjectEventParticipation;
use Konventix\Community\Presence\ConnectionState;
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
     * `parent::boot()` first — it loads the route files that will name
     * `throttle:shoutbox-post`, and the limiter itself is resolved per
     * request rather than at registration (Support's and Commerce's named
     * limiters are the same shape).
     */
    public function boot(): void
    {
        parent::boot();

        $this->configureRateLimiting();
    }

    /**
     * `scoped()`, not `singleton()`: this application runs on Octane, where
     * a singleton survives every request a worker ever serves
     * (`.ai/rules/providers.md`). `ConnectionState` holds nothing on `$this`
     * today — it resolves its Redis connection per call — and the scoped
     * binding is what keeps it that way if somebody later reaches for a
     * memo, because a scoped instance is forgotten between requests.
     *
     * Bound rather than left to auto-resolution so that the binding, and
     * therefore the lifetime rule, is written down somewhere.
     */
    protected function registerBindings(): void
    {
        $this->app->scoped(ConnectionState::class);
    }

    /**
     * The coarse half of D-COM-09's budget: 5 messages per 10 seconds,
     * keyed on the account so a LAN hall behind one NAT address does not
     * share one.
     *
     * **The exact axis lives in `PostMessage` and has to**, because the
     * rule is per person *per room* and only the Action has resolved which
     * room — the compose box posts to the participant's own running event
     * (D-COM-03), it never names a room. This limiter is the outer net that
     * refuses a flood before it reaches a controller; the Action is what
     * refuses a caller who skipped the route entirely (spec §6). Both read
     * the same two constants, so the two halves cannot drift apart.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('shoutbox-post', fn (Request $request): Limit => Limit::perSecond(
            PostMessage::MAX_PER_WINDOW,
            PostMessage::WINDOW_SECONDS,
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown')));
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

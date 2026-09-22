<?php

declare(strict_types=1);

namespace Konventix\Community\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Konventix\Admission\Events\ParticipantAdmitted;
use Konventix\Admission\Events\ParticipantHubAccessEnded;
use Konventix\Admission\Events\ParticipantPresenceChanged;
use Konventix\Community\Actions\PostMessage;
use Konventix\Community\Console\SweepExpiredRoomsCommand;
use Konventix\Community\Data\ShoutboxCardData;
use Konventix\Community\Events\ShoutboxMessagePosted;
use Konventix\Community\Events\ShoutboxMessageRemoved;
use Konventix\Community\Exceptions\ShoutboxWriteException;
use Konventix\Community\Gdpr\CommunityGdprHandler;
use Konventix\Community\Hub\ShoutboxCard;
use Konventix\Community\Listeners\BroadcastToRoom;
use Konventix\Community\Listeners\CloseRoomOnEventClosed;
use Konventix\Community\Listeners\Metrics\RecordShoutboxMetrics;
use Konventix\Community\Listeners\ProjectAdmissionBadge;
use Konventix\Community\Listeners\ProjectCommunityMember;
use Konventix\Community\Listeners\ProjectCommunityProfile;
use Konventix\Community\Listeners\ProjectEventParticipation;
use Konventix\Community\Metrics\CommunityMetrics;
use Konventix\Community\Presence\ConnectionState;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Event\Events\EventCancelled;
use Konventix\Event\Events\EventConcluded;
use Konventix\Identity\Events\BrandMembershipProfileUpdated;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Platform\Data\ActiveEventData;
use Konventix\Platform\Hub\HubCard;
use Konventix\Platform\Hub\HubRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Observability\MetricCatalog;
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
     * limiters are the same shape). The metric catalogue and the console
     * command are the two things the base class has no hook for, the same
     * reasoning `AdmissionServiceProvider` gives.
     */
    public function boot(): void
    {
        parent::boot();

        $this->configureRateLimiting();
        $this->registerMetrics();

        // The shoutbox's retention sweep (design §7), scheduled in
        // registerSchedule() and runnable by hand from docs/operations.md.
        $this->commands([
            SweepExpiredRoomsCommand::class,
        ]);
    }

    /**
     * Community's metrics (ADR-0059): catalogue entry here, recorders in
     * registerListeners() — `RecordShoutboxMetrics` for posted lines and
     * `BroadcastToRoom`, which counts the broadcast where it is sent.
     */
    protected function registerMetrics(): void
    {
        $this->app->make(MetricCatalog::class)->register(...CommunityMetrics::definitions());
    }

    /**
     * The shoutbox's 30-day clock (design §7, D-COM-05) runs out by the
     * calendar, not by an event anybody emits, so a sweep has to notice.
     * Daily is ample for a clock measured in days; `withoutOverlapping()`
     * because a run that deletes a large wall must not stack a second pass
     * on the same rooms. `callAfterResolving` so the scheduler is only built
     * in the role that runs it (the base class hook's own reasoning).
     */
    protected function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(SweepExpiredRoomsCommand::class)->daily()->withoutOverlapping();
        });
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
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown'))
            // The same refusal `PostMessage` throws, at 429: whichever half
            // of the budget refuses, the compose box reads one body — the
            // German sentence on `body` and the countdown's seconds.
            ->response(static fn (Request $request, array $headers): JsonResponse => ShoutboxWriteException::tooManyMessages(
                (int) ($headers['Retry-After'] ?? PostMessage::WINDOW_SECONDS),
            )->toJsonResponse(429)->withHeaders($headers)));
    }

    /**
     * The live hub's shoutbox card (design §8): the last few lines and an
     * inline compose box, for the admitted participants the hub is for.
     * Order 30, after Admission's ticket and Agenda's "Jetzt / Gleich" —
     * the code the door scans and what is on come first.
     *
     * No `permission`: reading the wall is admission, not a capability
     * (D-COM-03), and the hub renders only for an admitted member. The
     * registry hides the card on a brand with `community` off (ADR-0003).
     *
     * `static fn`, and `ShoutboxCard` resolved inside it: the registry is a
     * singleton that outlives the request under Octane
     * (`.ai/rules/providers.md`).
     */
    protected function registerHub(): void
    {
        $this->app->make(HubRegistry::class)->add(new HubCard(
            module: 'community',
            key: 'community.shoutbox',
            label: 'Shoutbox',
            component: 'community/hub/ShoutboxCard',
            payload: static fn (ActiveEventData $event, Authenticatable $user): ShoutboxCardData => app(ShoutboxCard::class)($event, $user),
            order: 30,
        ));
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
     *
     * `CloseRoomOnEventClosed` (design §7) gates itself on the event's
     * brand and starts the retention clock on `EventConcluded` and on
     * `EventCancelled` — a cancelled event never concludes, and its room
     * would otherwise never be swept. `BroadcastToRoom` is registered
     * **first** for both shoutbox events, so the room hears about a change
     * before any other listener reacts to it — see its docblock for why it,
     * and not the framework's `ShouldRescue`, sends the broadcast.
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
        Event::listen(EventConcluded::class, [CloseRoomOnEventClosed::class, 'handleConcluded']);
        Event::listen(EventCancelled::class, [CloseRoomOnEventClosed::class, 'handleCancelled']);
        Event::listen(ShoutboxMessagePosted::class, BroadcastToRoom::class);
        Event::listen(ShoutboxMessageRemoved::class, BroadcastToRoom::class);
        Event::listen(ShoutboxMessagePosted::class, RecordShoutboxMetrics::class);
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

<?php

declare(strict_types=1);

namespace Konventix\Admission\Providers;

use Illuminate\Support\Facades\Event;
use Konventix\Admission\Actions\SendClaimLink;
use Konventix\Admission\Console\AnnounceAdmissionOwnershipCommand;
use Konventix\Admission\Enums\Permission;
use Konventix\Admission\Events\AdmissionHolderAssigned;
use Konventix\Admission\Events\AdmissionHolderUnassigned;
use Konventix\Admission\Events\CheckInRejected;
use Konventix\Admission\Events\ClaimLinkIssued;
use Konventix\Admission\Events\ClaimLinkRevoked;
use Konventix\Admission\Events\ClaimLinkSent;
use Konventix\Admission\Gdpr\AdmissionGdprHandler;
use Konventix\Admission\Listeners\FillBuyerOnRegistrationOwnershipAnnounced;
use Konventix\Admission\Listeners\IssueAdmissionsOnRegistrationAmended;
use Konventix\Admission\Listeners\IssueAdmissionsOnRegistrationConfirmed;
use Konventix\Admission\Listeners\IssueCrewAdmissionOnAssignmentAccepted;
use Konventix\Admission\Listeners\Metrics\RecordAdmissionTokenMetrics;
use Konventix\Admission\Listeners\Metrics\RecordHolderMetrics;
use Konventix\Admission\Listeners\ProjectAdmissionSeat;
use Konventix\Admission\Listeners\ProjectEventDaysOnEventDaysChanged;
use Konventix\Admission\Listeners\ProjectEventSummary;
use Konventix\Admission\Listeners\ProjectPerson;
use Konventix\Admission\Listeners\ProjectReentryPolicyOnEventReentryPolicyChanged;
use Konventix\Admission\Listeners\RevokeAdmissionsOnRegistrationCancelled;
use Konventix\Admission\Metrics\AdmissionMetrics;
use Konventix\Admission\Support\BuildTicketManager;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDaysChanged;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventReentryPolicyChanged;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Events\VenueAssigned;
use Konventix\Event\Events\VenueUnassigned;
use Konventix\Event\Events\VenueUpdated;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Observability\MetricCatalog;
use Konventix\Platform\Permissions\DefaultRole;
use Konventix\Platform\Slots\SlotRegistry;
use Konventix\Registration\Events\RegistrationAmended;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationOwnershipAnnounced;
use Konventix\Resources\Events\ResourceBookingConfirmed;
use Konventix\Resources\Events\ResourceBookingReleased;
use Konventix\Staffing\Events\AssignmentAccepted;

/**
 * Registers everything this module owns. Registration is unconditional and
 * happens at boot; whether the module is *active* is a per-brand Pennant gate
 * applied at four points — routes, navigation, UI slots, listeners (ADR-0003).
 *
 * Never move any of this wiring into a central provider.
 */
class AdmissionServiceProvider extends ModuleServiceProvider
{
    /**
     * Core module: always active for every brand, never gated.
     */
    protected bool $gateable = false;

    /**
     * The module's Pennant feature name and route-middleware key.
     */
    public static function moduleName(): string
    {
        return 'admission';
    }

    /**
     * `parent::boot()` first — routes, listeners, slots and the GDPR
     * handler come from the base class hooks; the metric catalogue is the
     * one thing the base class has no hook for (ADR-0059), the same
     * reasoning `ResourcesServiceProvider`/`MessagingServiceProvider` give.
     */
    public function boot(): void
    {
        parent::boot();

        $this->registerMetrics();

        // The Resources read-model backfill (#292); see the command.
        $this->commands([AnnounceAdmissionOwnershipCommand::class]);
    }

    /**
     * Admission's metrics (ADR-0059): catalogue entry here, recorder in
     * registerListeners() below.
     */
    protected function registerMetrics(): void
    {
        $this->app->make(MetricCatalog::class)->register(...AdmissionMetrics::definitions());
    }

    /**
     * @return list<DefaultRole>
     */
    public function defaultRoles(): array
    {
        return [
            DefaultRole::of(
                'admission.entrance-staff',
                'Entrance Staff',
                Permission::ViewAdmissions,
                Permission::ScanCheckIn,
                Permission::ViewCheckInLog,
                Permission::PrintBadge,
                Permission::AssignHolder,
            ),
        ];
    }

    /**
     * `RegistrationConfirmed` and `RegistrationCancelled` are imported from
     * Registration's `Events\` namespace — the exempted case (ADR-0004); see
     * `IssueAdmissionsOnRegistrationConfirmed`'s docblock and
     * `tests/RegistrationCommerceImportBoundaryTest.php`.
     */
    protected function registerListeners(): void
    {
        Event::listen(
            RegistrationConfirmed::class,
            IssueAdmissionsOnRegistrationConfirmed::class,
        );

        // The delta counterpart (ADR-0036): grants added to an already-
        // confirmed registration issue exactly those, keyed on the amendment.
        Event::listen(
            RegistrationAmended::class,
            IssueAdmissionsOnRegistrationAmended::class,
        );

        // ADR-0057 point 6: the contractual act ended, so the access rights it
        // produced end with it. This is the only cancellation path into this
        // module — there is deliberately no listener on `PaymentReversed`,
        // because a door that evaluates money is ADR-0005's prohibition.
        Event::listen(
            RegistrationCancelled::class,
            RevokeAdmissionsOnRegistrationCancelled::class,
        );

        // Admission's read model of the two Event facts the door must decide
        // without a network round trip (ADR-0033). Admission may not read
        // Event's models (ADR-0004), so these projections are the only path
        // by which the day calendar and re-entry policy reach the entrance API.
        Event::listen(
            EventDaysChanged::class,
            ProjectEventDaysOnEventDaysChanged::class,
        );

        Event::listen(
            EventReentryPolicyChanged::class,
            ProjectReentryPolicyOnEventReentryPolicyChanged::class,
        );

        // D-STF-01: a rostered crew member gets a badge without a
        // registration behind it. Staffing emits, Admission listens, and
        // Staffing never writes here directly.
        Event::listen(
            AssignmentAccepted::class,
            IssueCrewAdmissionOnAssignmentAccepted::class,
        );

        // Admission's read model of a person's display name (design doc
        // §13, 2026-09-11 amendment): the ticket's holder-name fallback needs
        // one, and Admission may not read Identity's `User` model (ADR-0004).
        Event::listen(UserRegistered::class, ProjectPerson::class);
        Event::listen(UserProfileUpdated::class, ProjectPerson::class);

        // Admission's read model of an event's name, slug, schedule and
        // venue (design doc §13, 2026-09-11 amendment "Admission learns event
        // facts"): the ticket's event band needs them, and Admission may not
        // read Event's models (ADR-0004).
        Event::listen(EventCreated::class, [ProjectEventSummary::class, 'handleCreated']);
        Event::listen(EventDetailsUpdated::class, [ProjectEventSummary::class, 'handleDetailsUpdated']);
        Event::listen(EventRescheduled::class, [ProjectEventSummary::class, 'handleRescheduled']);
        Event::listen(VenueAssigned::class, [ProjectEventSummary::class, 'handleVenueAssigned']);
        Event::listen(VenueUpdated::class, [ProjectEventSummary::class, 'handleVenueUpdated']);
        Event::listen(VenueUnassigned::class, [ProjectEventSummary::class, 'handleVenueUnassigned']);

        // Which place each admission sits on (#292 §5), off Resources'
        // *confirmed* booking — a hold must never reach a ticket.
        Event::listen(ResourceBookingConfirmed::class, [ProjectAdmissionSeat::class, 'handleConfirmed']);
        Event::listen(ResourceBookingReleased::class, [ProjectAdmissionSeat::class, 'handleReleased']);

        // ADR-0059, #292 §9: holder changes and invitations.
        Event::listen(AdmissionHolderAssigned::class, [RecordHolderMetrics::class, 'handleAssigned']);
        Event::listen(AdmissionHolderUnassigned::class, [RecordHolderMetrics::class, 'handleUnassigned']);
        Event::listen(ClaimLinkIssued::class, [RecordHolderMetrics::class, 'handleIssued']);
        Event::listen(ClaimLinkSent::class, [RecordHolderMetrics::class, 'handleSent']);
        Event::listen(ClaimLinkRevoked::class, [RecordHolderMetrics::class, 'handleRevoked']);

        // Admission's own metric (ADR-0059, design doc §9): the one
        // rejection reason among CheckInRejected's nine that answers
        // whether §5's enforcement is rejecting real people.
        Event::listen(
            CheckInRejected::class,
            RecordAdmissionTokenMetrics::class,
        );

        // design doc §13, 2026-09-11 amendment "a registration-derived
        // admission is anonymous today": backfills `buyer_user_id` on
        // admissions that predate it. Only ever null -> value, never
        // overwrites a set one (FillBuyerOnRegistrationOwnershipAnnounced's
        // own whereNull guard) — this is the only listener this narrow
        // event may ever have (see the event's own docblock).
        Event::listen(
            RegistrationOwnershipAnnounced::class,
            FillBuyerOnRegistrationOwnershipAnnounced::class,
        );
    }

    /**
     * The participant's own, in the account menu (design §7). No
     * `permission`, and none is missing: the menu renders only for a
     * signed-in user and `MyTicketsController::index()` authorizes on
     * ownership, which is not a capability anybody can be granted — the
     * same reasoning Registration's own `My registrations` entry already
     * documents.
     */
    protected function registerNavigation(): void
    {
        $this->app->make(NavigationRegistry::class)->add(
            new NavigationItem(
                module: 'admission',
                label: 'My tickets',
                url: '/my/tickets',
                icon: 'qr-code',
                order: 11,
                area: NavigationItem::AREA_ACCOUNT,
            ),
        );
    }

    /**
     * Contributes the `admission/EventStatsCard` card to Event's
     * `event.admin.dashboard` UI slot (ADR-0003 enforcement point 3,
     * docs/decisions/0003). The component fetches its own numbers from
     * `AdmissionStatsController` — Event never queries this module's models
     * directly.
     */
    /**
     * The claim-link invitation (#292 §4.6, #16 point 1): sending is
     * Messaging's concern, this module only declares what its mail says.
     * Every directive on its own line (`.ai/rules/app-modules-src.md`).
     */
    protected function registerMessageTemplates(): void
    {
        $this->app->make(TemplateRegistry::class)->register(new MessageTemplate(
            key: SendClaimLink::TEMPLATE_KEY,
            subjects: [
                'de' => 'Ein Ticket wartet auf Sie',
                'en' => 'A ticket is waiting for you',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    @if ($managerName)
                    <p>{{ $managerName }} hat ein Ticket für Sie reserviert{{ $eventName ? ' — '.$eventName : '' }}.</p>
                    @else
                    <p>Ein Ticket wurde für Sie reserviert{{ $eventName ? ' — '.$eventName : '' }}.</p>
                    @endif
                    <p>Um es auf Ihren Namen zu übernehmen, melden Sie sich an oder legen Sie ein Konto an, und bestätigen Sie dann:</p>
                    <p><a href="{{ $claimUrl }}">Ticket übernehmen</a></p>
                    <p>Der Link gilt bis {{ $expiresAt }} und nur für diese E-Mail-Adresse.</p>
                    BLADE,
                'en' => <<<'BLADE'
                    @if ($managerName)
                    <p>{{ $managerName }} has reserved a ticket for you{{ $eventName ? ' — '.$eventName : '' }}.</p>
                    @else
                    <p>A ticket has been reserved for you{{ $eventName ? ' — '.$eventName : '' }}.</p>
                    @endif
                    <p>To put it in your name, sign in or create an account, then confirm:</p>
                    <p><a href="{{ $claimUrl }}">Claim your ticket</a></p>
                    <p>The link is valid until {{ $expiresAt }} and only for this e-mail address.</p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'eventName' => 'SXLan 2027',
                'managerName' => 'Ada Lovelace',
                'role' => 'visitor',
                'claimUrl' => 'https://example.test/admissions/claim/abc123',
                'expiresAt' => '28.08.2026 18:00',
            ],
        ));
    }

    protected function registerSlots(): void
    {
        $slots = $this->app->make(SlotRegistry::class);

        $slots->add(
            slot: 'event.admin.dashboard',
            module: 'admission',
            component: 'admission/EventStatsCard',
            props: [],
            order: 20,
        );

        // The ticket manager (#292 §4.5, #16): one row per admission the
        // registration granted, with the holder, the live invitation and
        // the seat — and the download of the buyer's combined file. Absent
        // (`manager: null`) when the registration granted no admissions, or
        // every one has been revoked — `SeatSummarySlot`'s idiom on this
        // same slot. `static`: SlotRegistry is a singleton that outlives the
        // request under Octane, and a closure capturing `$this` would pin
        // this provider in memory with it; the builder is resolved per call
        // for the same reason.
        $slots->add(
            slot: 'registration.mine.show',
            module: 'admission',
            component: 'admission/TicketManagerSlot',
            props: static fn (array $context): array => [
                'manager' => app(BuildTicketManager::class)
                    ->handle($context['registrationId'], (string) auth()->id())
                    ?->toArray(),
            ],
            order: 30,
        );
    }

    /**
     * Admissions stay; the invitation addresses this module stored itself do
     * not (ADR-0004: Compliance orchestrates, this module touches its rows).
     */
    protected function registerGdprExporters(): void
    {
        $this->app->make(GdprHandlerRegistry::class)->register(new AdmissionGdprHandler);
    }
}

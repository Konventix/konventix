<?php

declare(strict_types=1);

namespace Konventix\Admission\Providers;

use Illuminate\Support\Facades\Event;
use Konventix\Admission\Enums\Permission;
use Konventix\Admission\Events\CheckInRejected;
use Konventix\Admission\Gdpr\AdmissionGdprHandler;
use Konventix\Admission\Listeners\IssueAdmissionsOnRegistrationAmended;
use Konventix\Admission\Listeners\IssueAdmissionsOnRegistrationConfirmed;
use Konventix\Admission\Listeners\IssueCrewAdmissionOnAssignmentAccepted;
use Konventix\Admission\Listeners\Metrics\RecordAdmissionTokenMetrics;
use Konventix\Admission\Listeners\ProjectEventDaysOnEventDaysChanged;
use Konventix\Admission\Listeners\ProjectReentryPolicyOnEventReentryPolicyChanged;
use Konventix\Admission\Listeners\RevokeAdmissionsOnRegistrationCancelled;
use Konventix\Admission\Metrics\AdmissionMetrics;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Event\Events\EventDaysChanged;
use Konventix\Event\Events\EventReentryPolicyChanged;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Observability\MetricCatalog;
use Konventix\Platform\Permissions\DefaultRole;
use Konventix\Platform\Slots\SlotRegistry;
use Konventix\Registration\Events\RegistrationAmended;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Events\RegistrationConfirmed;
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

        // Admission's own metric (ADR-0059, design doc §9): the one
        // rejection reason among CheckInRejected's nine that answers
        // whether §5's enforcement is rejecting real people.
        Event::listen(
            CheckInRejected::class,
            RecordAdmissionTokenMetrics::class,
        );
    }

    /**
     * Contributes the `admission/EventStatsCard` card to Event's
     * `event.admin.dashboard` UI slot (ADR-0003 enforcement point 3,
     * docs/decisions/0003). The component fetches its own numbers from
     * `AdmissionStatsController` — Event never queries this module's models
     * directly.
     */
    protected function registerSlots(): void
    {
        $this->app->make(SlotRegistry::class)->add(
            slot: 'event.admin.dashboard',
            module: 'admission',
            component: 'admission/EventStatsCard',
            props: [],
            order: 20,
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

<?php

declare(strict_types=1);

namespace Konventix\Admission\Providers;

use Illuminate\Support\Facades\Event;
use Konventix\Admission\Listeners\IssueAdmissionsOnRegistrationAmended;
use Konventix\Admission\Listeners\IssueAdmissionsOnRegistrationConfirmed;
use Konventix\Admission\Listeners\ProjectEventDaysOnEventDaysChanged;
use Konventix\Admission\Listeners\ProjectReentryPolicyOnEventReentryPolicyChanged;
use Konventix\Event\Events\EventDaysChanged;
use Konventix\Event\Events\EventReentryPolicyChanged;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Slots\SlotRegistry;
use Konventix\Registration\Events\RegistrationAmended;
use Konventix\Registration\Events\RegistrationConfirmed;

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
     * `RegistrationConfirmed` is imported from Registration's `Events\`
     * namespace — the one exempted case (ADR-0004); see
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
}

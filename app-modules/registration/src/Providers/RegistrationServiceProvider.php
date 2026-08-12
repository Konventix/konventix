<?php

declare(strict_types=1);

namespace Konventix\Registration\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Konventix\Commerce\Events\InvoiceIssued;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Slots\SlotRegistry;
use Konventix\Registration\Console\ExpireWaitlistOffersCommand;
use Konventix\Registration\Enums\Permission;
use Konventix\Registration\Events\QuotaReleased;
use Konventix\Registration\Gdpr\RegistrationGdprHandler;
use Konventix\Registration\Listeners\MarkInvoicedOnInvoiceIssued;
use Konventix\Registration\Listeners\ProjectRegistrant;
use Konventix\Registration\Listeners\PromoteOnQuotaReleased;
use Konventix\Registration\Listeners\RecordPaymentOnOrderPaid;

/**
 * Registers everything this module owns. Registration is unconditional and
 * happens at boot; whether the module is *active* is a per-brand Pennant gate
 * applied at four points — routes, navigation, UI slots, listeners (ADR-0003).
 *
 * Never move any of this wiring into a central provider.
 */
class RegistrationServiceProvider extends ModuleServiceProvider
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
        return 'registration';
    }

    /**
     * Registration learns payment outcomes from Commerce's events, never
     * from Commerce models (ADR-0006, SPEC.md "Consumes").
     */
    protected function registerListeners(): void
    {
        Event::listen(OrderPaid::class, RecordPaymentOnOrderPaid::class);

        // D-REG-12: a freed quota unit promotes the oldest queued waitlist
        // entry — including units released by expired offers, which is what
        // makes the queue drain itself.
        Event::listen(QuotaReleased::class, PromoteOnQuotaReleased::class);

        // ADR-0036: billing party freezes at first invoice.
        Event::listen(InvoiceIssued::class, MarkInvoicedOnInvoiceIssued::class);

        // Registrant read model for organizer lists (ADR-0004).
        Event::listen(UserRegistered::class, ProjectRegistrant::class);
        Event::listen(UserProfileUpdated::class, ProjectRegistrant::class);
    }

    protected function registerGdprExporters(): void
    {
        $this->app->make(GdprHandlerRegistry::class)->register(new RegistrationGdprHandler);
    }

    protected function registerNavigation(): void
    {
        $this->app->make(NavigationRegistry::class)->add(
            new NavigationItem(
                module: 'registration',
                label: 'Registrations',
                url: '/admin/registrations',
                icon: 'clipboard-list',
                order: 20,
                permission: Permission::ViewRegistrations->value,
                area: NavigationItem::AREA_ADMIN,
            ),
        );
    }

    /**
     * The waitlist-offer mail (D-REG-12); sending is Messaging's concern,
     * this module only declares what its mail says.
     */
    protected function registerMessageTemplates(): void
    {
        $this->app->make(TemplateRegistry::class)->register(new MessageTemplate(
            key: 'registration.waitlist-offer',
            subjects: [
                'de' => 'Ein Platz ist frei geworden',
                'en' => 'A spot has opened up',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Gute Nachrichten: Auf der Warteliste ist ein Platz für Sie frei geworden.</p>
                    <p><a href="{{ $claimUrl }}">Platz jetzt verbindlich annehmen</a></p>
                    <p>Das Angebot gilt bis {{ $offerExpiresAt }} — danach rückt die nächste Person nach.</p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Good news: a waitlist spot has opened up for you.</p>
                    <p><a href="{{ $claimUrl }}">Claim your spot now</a></p>
                    <p>The offer is valid until {{ $offerExpiresAt }} — after that it passes to the next person in line.</p>
                    BLADE,
            ],
            defaultLocale: 'de',
        ));
    }

    /**
     * Offer expiry runs on the scheduler; expiry releases the held units,
     * which re-triggers promotion (see ExpireWaitlistOffers).
     */
    public function boot(): void
    {
        parent::boot();

        $this->commands([ExpireWaitlistOffersCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('registration:expire-waitlist-offers')->everyFifteenMinutes();
        });
    }

    /**
     * Contributes the `registration/EventStatsCard` card to Event's
     * `event.admin.dashboard` UI slot (ADR-0003 enforcement point 3,
     * docs/decisions/0003). The component fetches its own numbers from
     * `RegistrationStatsController` — Event never queries this module's
     * models directly.
     */
    protected function registerSlots(): void
    {
        $this->app->make(SlotRegistry::class)->add(
            slot: 'event.admin.dashboard',
            module: 'registration',
            component: 'registration/EventStatsCard',
            props: [],
            order: 10,
        );
    }
}

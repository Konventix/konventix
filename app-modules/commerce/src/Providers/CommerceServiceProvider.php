<?php

declare(strict_types=1);

namespace Konventix\Commerce\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Konventix\Admission\Events\AdmissionCheckedIn;
use Konventix\Commerce\Console\RunDunningSweep;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Listeners\CreateOrderOnSettlementRequested;
use Konventix\Commerce\Listeners\ProjectCheckInOnAdmissionCheckedIn;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\ManualSettlementAdapter;
use Konventix\Commerce\Settlement\Mollie\MollieApi;
use Konventix\Commerce\Settlement\Mollie\MollieSettlementAdapter;
use Konventix\Commerce\Settlement\Mollie\SdkMollieApi;
use Konventix\Commerce\Settlement\NullSettlementAdapter;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;
use Konventix\Commerce\Settlement\Stripe\SdkStripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeSettlementAdapter;
use Konventix\Commerce\Settlement\Unzer\SdkUnzerApi;
use Konventix\Commerce\Settlement\Unzer\UnzerApi;
use Konventix\Commerce\Settlement\Unzer\UnzerSettlementAdapter;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Registration\Events\RegistrationSettlementRequested;
use RuntimeException;
use Stripe\StripeClient;

/**
 * Registers everything this module owns. Registration is unconditional and
 * happens at boot; whether the module is *active* is a per-brand Pennant gate
 * applied at four points — routes, navigation, UI slots, listeners (ADR-0003).
 *
 * Never move any of this wiring into a central provider.
 */
class CommerceServiceProvider extends ModuleServiceProvider
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
        return 'commerce';
    }

    /**
     * Commerce participates exactly when a registration type declares a
     * settlement that moves money (ADR-0026): this listener is the module's
     * entire inbound surface today.
     */
    protected function registerListeners(): void
    {
        Event::listen(RegistrationSettlementRequested::class, CreateOrderOnSettlementRequested::class);

        // The ADR-0037 read model. D-COM-15 forbids Commerce querying
        // Admission's tables, so this projection is the *only* way the refund
        // guard can know a registration was used.
        Event::listen(AdmissionCheckedIn::class, ProjectCheckInOnAdmissionCheckedIn::class);
    }

    /**
     * The settlement-adapter registry (ADR-0007). Registered as a singleton so
     * providers can be added by other slices without touching call sites —
     * Stripe and Mollie (Slice 7b) become two `register()` lines rather than
     * a rewrite of every place money is settled.
     */
    protected function registerBindings(): void
    {
        $this->app->singleton(SettlementAdapterRegistry::class, function (): SettlementAdapterRegistry {
            $registry = new SettlementAdapterRegistry;

            $registry->register(SettlementAccount::PROVIDER_MANUAL, ManualSettlementAdapter::class);
            $registry->register(SettlementAccount::PROVIDER_NULL, NullSettlementAdapter::class);
            $registry->register(SettlementAccount::PROVIDER_STRIPE, StripeSettlementAdapter::class);
            $registry->register(SettlementAccount::PROVIDER_MOLLIE, MollieSettlementAdapter::class);
            $registry->register(SettlementAccount::PROVIDER_UNZER, UnzerSettlementAdapter::class);

            return $registry;
        });

        // Mollie and Unzer have no platform credential (ADR-0039): the
        // brand's own key travels with every call, so the bindings need no
        // config at all.
        $this->app->bind(MollieApi::class, SdkMollieApi::class);
        $this->app->bind(UnzerApi::class, SdkUnzerApi::class);

        // Bound lazily: the platform key is only required the moment a
        // Stripe-connected brand actually settles something (ADR-0039).
        $this->app->bind(StripeApi::class, function (): StripeApi {
            $secret = config('services.stripe.secret');

            if (! is_string($secret) || $secret === '') {
                throw new RuntimeException(
                    'STRIPE_SECRET is not configured; a Stripe-connected brand cannot settle without the platform key (ADR-0039).',
                );
            }

            return new SdkStripeApi(new StripeClient($secret));
        });
    }

    /**
     * The daily dunning sweep. Scheduled here rather than in a central kernel
     * — module wiring never goes into a central provider (ADR-0003).
     */
    protected function registerSchedule(): void
    {
        // Registered explicitly rather than relying on a discovery convention:
        // modular auto-discovers nothing under a module's `src/Console`, and a
        // sweep that silently fails to register would look like "no invoices
        // are overdue" rather than like a bug.
        if ($this->app->runningInConsole()) {
            $this->commands([RunDunningSweep::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(RunDunningSweep::class)->dailyAt('07:00');
        });
    }

    /**
     * The invoice mail (SPEC.md "Does not own" — sending is Messaging's;
     * this module only declares what its mail says). German default: the
     * release targets domestic DACH events (ADR-0030's framing), and the
     * billing party's locale is unknown for account-less recipients.
     */
    protected function registerMessageTemplates(): void
    {
        $this->app->make(TemplateRegistry::class)->register(new MessageTemplate(
            key: 'commerce.invoice-issued',
            subjects: [
                'de' => 'Rechnung {{ $invoiceNumber }} von {{ $sellerName }}',
                'en' => 'Invoice {{ $invoiceNumber }} from {{ $sellerName }}',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Guten Tag {{ $recipientName }},</p>
                    <p>anbei erhalten Sie die Rechnung <strong>{{ $invoiceNumber }}</strong>
                    über <strong>{{ $totalGross }} {{ $currency }}</strong>.</p>
                    <p>Bitte begleichen Sie den Betrag bis zum <strong>{{ $dueOn }}</strong>
                    unter Angabe der Rechnungsnummer.</p>
                    <p>Mit freundlichen Grüßen<br>{{ $sellerName }}</p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Dear {{ $recipientName }},</p>
                    <p>please find attached invoice <strong>{{ $invoiceNumber }}</strong>
                    for <strong>{{ $totalGross }} {{ $currency }}</strong>.</p>
                    <p>Payment is due by <strong>{{ $dueOn }}</strong>, quoting the
                    invoice number.</p>
                    <p>Kind regards<br>{{ $sellerName }}</p>
                    BLADE,
            ],
            defaultLocale: 'de',
        ));
    }

    protected function registerNavigation(): void
    {
        $registry = $this->app->make(NavigationRegistry::class);

        $registry->add(
            new NavigationItem(
                module: 'commerce',
                label: 'Orders',
                url: '/admin/orders',
                icon: 'receipt',
                order: 25,
                permission: Permission::ViewOrders->value,
                area: NavigationItem::AREA_ADMIN,
            ),
        );

        $registry->add(
            new NavigationItem(
                module: 'commerce',
                label: 'Settlement',
                url: '/admin/settlement-accounts',
                icon: 'landmark',
                order: 26,
                permission: Permission::ManageSettlementAccount->value,
                area: NavigationItem::AREA_ADMIN,
            ),
        );
    }
}

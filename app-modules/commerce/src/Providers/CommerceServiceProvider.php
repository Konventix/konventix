<?php

declare(strict_types=1);

namespace Konventix\Commerce\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Konventix\Admission\Events\AdmissionCheckedIn;
use Konventix\Commerce\Console\ReportInvoiceRetention;
use Konventix\Commerce\Console\RunDunningSweep;
use Konventix\Commerce\Data\RegistrationSettlementData;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Listeners\CreateOrderOnSettlementRequested;
use Konventix\Commerce\Listeners\ProjectCheckInOnAdmissionCheckedIn;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
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
use Konventix\Commerce\Storage\InvoiceStorageMigration;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Slots\SlotRegistry;
use Konventix\Platform\Storage\StorageMigrationRegistry;
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
     * The GoBD invoice archive (ADR-0043). Copies are verified against the
     * sha256 sealed onto each invoice, so a migration proves the archived
     * bytes rather than merely moving them (ADR-0031).
     */
    protected function registerStorageMigrations(): void
    {
        $this->app->make(StorageMigrationRegistry::class)->register(new InvoiceStorageMigration);
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
            $this->commands([RunDunningSweep::class, ReportInvoiceRetention::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(RunDunningSweep::class)->dailyAt('07:00');

            // Reports only (ADR-0043 point 9). Daily rather than at boot:
            // under Octane "boot" is every worker start, which would make it
            // noise instead of a control.
            $schedule->command(ReportInvoiceRetention::class)->dailyAt('07:15');
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

    /**
     * What a registration cost and whether it is settled, contributed to
     * Registration's own account page.
     *
     * This is the only direction that works. An order is keyed by
     * `settles_type`/`settles_id`, so Commerce cannot resolve "which orders
     * belong to this person" without reading Registration's tables (ADR-0004
     * forbids it — the same boundary that leaves this module without a GDPR
     * handler). Asked about one registration whose ownership the caller has
     * already established, it can answer completely. A standalone "my
     * invoices" list still cannot be built this way and still needs the
     * payer read model the ROADMAP carries as an open decision.
     *
     * `static` because SlotRegistry is a singleton that outlives the request
     * under Octane, and a closure capturing `$this` would pin this provider
     * in memory with it.
     */
    protected function registerSlots(): void
    {
        $this->app->make(SlotRegistry::class)->add(
            slot: 'registration.mine.show',
            module: 'commerce',
            component: 'commerce/RegistrationSettlement',
            props: static function (array $context): array {
                // ADR-0036 made "one order per registration" false; the one
                // that matters to the payer is the newest.
                $order = Order::query()
                    ->where('settles_type', 'registration')
                    ->where('settles_id', $context['registrationId'])
                    ->latest('created_at')
                    ->first();

                if ($order === null) {
                    // Free settlement creates no order at all (ADR-0026), so
                    // "nothing here" is the correct and common answer.
                    return ['settlement' => null];
                }

                $invoice = Invoice::query()
                    ->where('order_id', $order->getKey())
                    ->latest('issued_on')
                    ->first();

                return [
                    'settlement' => RegistrationSettlementData::ofOrder(
                        $order,
                        $invoice,
                        payUrl: $order->status === OrderStatus::Pending
                            ? route('commerce.pay.show', ['registration' => $context['registrationId']], absolute: false)
                            : null,
                        documentUrl: $invoice?->document_path === null ? null : URL::temporarySignedRoute(
                            'commerce.my.invoice.document',
                            now()->addMinutes(30),
                            ['invoice' => $invoice->getKey()],
                            absolute: false,
                        ),
                    )->toArray(),
                ];
            },
            order: 10,
        );
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

        $registry->add(
            new NavigationItem(
                module: 'commerce',
                label: 'Bookkeeping',
                url: '/admin/bookkeeping',
                icon: 'book-open-check',
                order: 27,
                permission: Permission::ExportBookkeeping->value,
                area: NavigationItem::AREA_ADMIN,
            ),
        );
    }
}

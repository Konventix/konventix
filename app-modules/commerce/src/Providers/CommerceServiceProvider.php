<?php

declare(strict_types=1);

namespace Konventix\Commerce\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Konventix\Admission\Events\AdmissionCheckedIn;
use Konventix\Commerce\Console\CancelUnpaidReversals;
use Konventix\Commerce\Console\ExpireCartsCommand;
use Konventix\Commerce\Console\ProbeInvoiceNumbering;
use Konventix\Commerce\Console\ReconcilePayments;
use Konventix\Commerce\Console\ReportInvoiceRetention;
use Konventix\Commerce\Console\RunDunningSweep;
use Konventix\Commerce\Console\WaitingRoomTick;
use Konventix\Commerce\Data\RegistrationSettlementData;
use Konventix\Commerce\Data\ShopProductData;
use Konventix\Commerce\Data\WaitingRoomSettingsData;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\OrderPlaced;
use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Events\PaymentReversalRecorded;
use Konventix\Commerce\Events\PaymentReversed;
use Konventix\Commerce\Events\PaymentUnapplied;
use Konventix\Commerce\Events\StockExhausted;
use Konventix\Commerce\Gdpr\CommerceGdprHandler;
use Konventix\Commerce\Listeners\CreateOrderOnSettlementRequested;
use Konventix\Commerce\Listeners\IssueInvoiceOnOrderPaid;
use Konventix\Commerce\Listeners\NotifyOperatorsOnPaymentFailed;
use Konventix\Commerce\Listeners\NotifyOperatorsOnPaymentReversalRecorded;
use Konventix\Commerce\Listeners\NotifyOperatorsOnPaymentUnapplied;
use Konventix\Commerce\Listeners\NotifyOperatorsOnStockExhausted;
use Konventix\Commerce\Listeners\ProjectCheckInOnAdmissionCheckedIn;
use Konventix\Commerce\Listeners\SendOrderConfirmationOnOrderPlaced;
use Konventix\Commerce\Listeners\SendPaymentFailedMailOnPaymentFailed;
use Konventix\Commerce\Listeners\SendPaymentReversedMailOnPaymentReversed;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Models\WaitingRoomSettings;
use Konventix\Commerce\Settlement\LocalHostedCheckoutAdapter;
use Konventix\Commerce\Settlement\ManualSettlementAdapter;
use Konventix\Commerce\Settlement\Mollie\MollieApi;
use Konventix\Commerce\Settlement\Mollie\MollieSettlementAdapter;
use Konventix\Commerce\Settlement\Mollie\SdkMollieApi;
use Konventix\Commerce\Settlement\NullSettlementAdapter;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;
use Konventix\Commerce\Settlement\Stripe\SdkStripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeCredentials;
use Konventix\Commerce\Settlement\Stripe\StripeNotConfiguredException;
use Konventix\Commerce\Settlement\Stripe\StripeSettlementAdapter;
use Konventix\Commerce\Settlement\Unzer\SdkUnzerApi;
use Konventix\Commerce\Settlement\Unzer\UnzerApi;
use Konventix\Commerce\Settlement\Unzer\UnzerSettlementAdapter;
use Konventix\Commerce\Storage\InvoiceStorageMigration;
use Konventix\Commerce\WaitingRoom\ArrayWaitingRoomStore;
use Konventix\Commerce\WaitingRoom\CommerceWaitingRoomGate;
use Konventix\Commerce\WaitingRoom\RedisWaitingRoomStore;
use Konventix\Commerce\WaitingRoom\WaitingRoomStore;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Messaging\Topics\Topic;
use Konventix\Messaging\Topics\TopicRegistry;
use Konventix\Platform\Cookies\CookieDeclaration;
use Konventix\Platform\Cookies\CookieRegistry;
use Konventix\Platform\Enums\CookieCategory;
use Konventix\Platform\Features\BrandFeature;
use Konventix\Platform\Features\BrandFeatureRegistry;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Permissions\DefaultRole;
use Konventix\Platform\Slots\SlotRegistry;
use Konventix\Platform\Storage\StorageMigrationRegistry;
use Konventix\Platform\WaitingRoom\WaitingRoomGate;
use Konventix\Registration\Events\RegistrationSettlementRequested;
use Laravel\Pennant\Feature;
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
     * ADR-0045: the shop's own starting point — viewing and managing the
     * catalogue plus the orders it produces, without every other Commerce
     * capability (invoicing, refunds, settlement accounts) a shop manager
     * has no business touching.
     *
     * @return list<DefaultRole>
     */
    public function defaultRoles(): array
    {
        return [
            DefaultRole::of(
                'commerce.shop-manager',
                'Shop Manager',
                Permission::ViewProducts,
                Permission::ManageProducts,
                Permission::ViewOrders,
            ),
        ];
    }

    /**
     * `parent::boot()` first — the route files it loads carry the
     * `throttle:commerce-webhooks` middleware the limiter below names, and
     * the limiter itself is resolved per request, not at registration.
     */
    public function boot(): void
    {
        parent::boot();

        $this->configureRateLimiting();
    }

    /**
     * The PSP webhook throttle (#71). `bootstrap/app.php` declares no `api`
     * throttler, so without this the three endpoints of
     * `routes/api/commerce.php` are unlimited — and Mollie's and Unzer's
     * handlers answer *every* request with a call to the provider's own API
     * (ADR-0039's fetch-back model), so a flood spends the organizer's own
     * API quota, not just log lines. Stripe's is cheaper (a signature check
     * first), but the limiter is per route group so all three share it.
     *
     * 120 per minute per IP is deliberately generous: it is a ceiling on
     * abuse, never a shaper of real traffic. A provider replaying a backlog
     * after an outage, or a busy sale settling, arrives in bursts from a
     * small set of provider IPs, and a webhook the PSP retries for days
     * because we answered 429 is worse than the flood this guards against.
     * Keyed by IP rather than by route: one attacker must not be able to
     * lock out a provider by flooding a different endpoint.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('commerce-webhooks', fn (Request $request): Limit => Limit::perMinute(120)->by($request->ip() ?? 'unknown'));
    }

    /**
     * ADR-0047: Commerce is Core and cannot be switched off, so the shop and
     * the waiting room in front of it are per-brand Pennant features instead
     * of module gates. Both default to off — a brand opts in explicitly.
     */
    protected function registerFeatures(): void
    {
        Feature::define('commerce:shop', fn (): bool => false);
        Feature::define('commerce:waiting-room', fn (): bool => false);
    }

    /**
     * Declares the two features above with Platform's `BrandFeatureRegistry`
     * so the brand page's Features card has something to list.
     */
    protected function registerBrandFeatures(): void
    {
        $this->app->make(BrandFeatureRegistry::class)->register(
            new BrandFeature(
                name: 'commerce:shop',
                label: 'Shop',
                description: 'A person-owned cart settles registrations and goods in one order.',
                module: self::moduleName(),
            ),
            new BrandFeature(
                name: 'commerce:waiting-room',
                label: 'Waiting room',
                description: 'Holds arrivals in a queue and admits them in batches so a rush cannot oversell.',
                module: self::moduleName(),
                // The brand edit page's own extension point (ADR-0047
                // point 5, `Platform\Features\BrandFeature`'s doc), not a
                // `SlotRegistry` slot: `SlotRegistry`'s feature gate reads
                // the *ambient* `BrandContext::current()`, which the admin
                // host never resolves (ADR-0029) — this closure instead
                // receives the route-bound `Brand` explicitly, the same
                // brand `BrandAdminController::edit()` already has.
                settingsComponent: 'commerce/admin/WaitingRoomSettingsCard',
                settingsProps: static fn (Brand $brand): array => [
                    'brandId' => $brand->getKey(),
                    'settings' => WaitingRoomSettingsData::ofModel(
                        WaitingRoomSettings::forBrand($brand->getKey()),
                    )->toArray(),
                ],
            ),
        );
    }

    /**
     * The waiting room's ticket cookie (ADR-0047 point 5). Its name carries
     * the brand id (`TicketCookie::name()`), so the declared name here is a
     * pattern, not a literal value — the same idiom Platform's own
     * `registerCookies()` uses for the brand-prefixed session cookie.
     */
    protected function registerCookies(): void
    {
        $this->app->make(CookieRegistry::class)->register(
            new CookieDeclaration('waiting_room_*', 'Your place in the queue while a sale is busy.', CookieCategory::Necessary, '30 minutes', 'commerce'),
        );
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

        // ADR-0048: a paid order gets its Rechnung the moment it is paid —
        // card-paid registrations and every cart order got no document
        // before this.
        Event::listen(OrderPaid::class, IssueInvoiceOnOrderPaid::class);

        // ADR-0048 point 3: the module that owns an event owns its mail —
        // a cart checkout's own confirmation, a failed payment explained to
        // the payer, and the shop manager told about both a sold-out
        // variant and a payment that did not go through.
        Event::listen(OrderPlaced::class, SendOrderConfirmationOnOrderPlaced::class);
        Event::listen(PaymentFailed::class, SendPaymentFailedMailOnPaymentFailed::class);
        Event::listen(StockExhausted::class, NotifyOperatorsOnStockExhausted::class);
        Event::listen(PaymentFailed::class, NotifyOperatorsOnPaymentFailed::class);

        // ADR-0057 point 8: both sides of a reversal. The operator hears
        // about every row that means something — an opened dispute, a final
        // return or lost dispute — off the module-internal
        // `PaymentReversalRecorded`; the payer hears about the money leaving
        // again off the cross-domain `PaymentReversed`, which fires only
        // when a reversal is final. A won dispute produces neither.
        Event::listen(PaymentReversalRecorded::class, NotifyOperatorsOnPaymentReversalRecorded::class);
        Event::listen(PaymentReversed::class, SendPaymentReversedMailOnPaymentReversed::class);

        // ADR-0057 point 9 / #6: money for an order the grace sweep already
        // cancelled. Operators only — what happens next is their decision.
        Event::listen(PaymentUnapplied::class, NotifyOperatorsOnPaymentUnapplied::class);
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

            // `null` is the honest "no PSP connected" adapter everywhere in
            // production — it cannot host a checkout, so a cart never
            // reaches PlaceOrderFromCart's `ProvidesHostedCheckout` branch
            // for it (CartCheckoutRefusedException::noOnlinePayment()).
            // `local`/`testing` swap in LocalHostedCheckoutAdapter instead
            // (ADR-0047 point 6): a k6 rush against a seeded demo brand has
            // nothing to onboard a real PSP with, but still needs to
            // exercise the shop's actual checkout path end to end. See that
            // class's docblock for why this is scoped this narrowly.
            $registry->register(
                SettlementAccount::PROVIDER_NULL,
                $this->app->environment('local', 'testing') ? LocalHostedCheckoutAdapter::class : NullSettlementAdapter::class,
            );

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

        // Scoped, not bound fresh per resolution: the console asks the same
        // question eight times to render one card and once more per
        // Stripe-enabled brand (`SettlementAccount::isOffered()`), and the
        // settings row cannot change inside a request. `scoped()` rather than
        // `singleton()` because Octane clears scoped instances between
        // requests, so a key rotated in the console is live for the next one —
        // see the class's own doc.
        $this->app->scoped(StripeCredentials::class);

        // The one place the Stripe key is resolved (ADR-0058 point 2):
        // `StripeCredentials` answers row-before-environment, and every
        // call is made on this instance's own account — no Connect, no
        // `Stripe-Account` header. Bound lazily: the key is only required
        // the moment something actually calls Stripe.
        //
        // It throws on the **secret key alone**, deliberately narrower than
        // `StripeCredentials::configured()`, which also wants the webhook
        // signing secret: a refund and the reconciliation sweep need only the
        // key, and refusing to build a client without a signing secret would
        // turn a half-configured instance into an outage instead of a closed
        // door on new checkouts.
        //
        // Nothing catches the exception. It does not need catching on the
        // paths that matter: a payer never reaches here, because
        // `SettlementAccount::isOffered()` requires the pair before Stripe is
        // offered at all and `CheckoutController` re-checks it on submit, and
        // the console never provokes a client — it reads `StripeSetupData` and
        // names the missing half itself. What is left is the message, for
        // whoever meets this in a log, a failed job or a console command; the
        // typed class keeps that message findable (#194).
        $this->app->bind(StripeApi::class, function (): StripeApi {
            $secret = $this->app->make(StripeCredentials::class)->secret();

            if ($secret === null) {
                throw StripeNotConfiguredException::missingPlatformKey();
            }

            return new SdkStripeApi(new StripeClient($secret));
        });

        // The waiting room (ADR-0047 point 5). ArrayWaitingRoomStore is a
        // singleton — safe only because it holds no request-scoped state,
        // just the queue's own (see its own doc) — and must only ever be
        // bound for `WAITING_ROOM_STORE=array` (phpunit.xml). Everything
        // else binds RedisWaitingRoomStore, a fresh instance per
        // resolution: it holds no state of its own, Redis does.
        $this->app->singleton(ArrayWaitingRoomStore::class);

        $this->app->bind(WaitingRoomStore::class, fn (): WaitingRoomStore => config('commerce.waiting_room.store') === 'array'
            ? $this->app->make(ArrayWaitingRoomStore::class)
            : new RedisWaitingRoomStore);

        // Platform's contract, Commerce's queue (ADR-0047 point 5) — every
        // module standing behind the door resolves this through Platform's
        // `ensure-waiting-room` alias, never this binding directly.
        $this->app->bind(WaitingRoomGate::class, CommerceWaitingRoomGate::class);
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
     * Export by the payer column (ADR-0044); erase nothing — see the handler.
     */
    protected function registerGdprExporters(): void
    {
        $this->app->make(GdprHandlerRegistry::class)->register(new CommerceGdprHandler);
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
            $this->commands([RunDunningSweep::class, ReportInvoiceRetention::class, ExpireCartsCommand::class, WaitingRoomTick::class, ProbeInvoiceNumbering::class, ReconcilePayments::class, CancelUnpaidReversals::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(RunDunningSweep::class)->dailyAt('07:00');

            // Reports only (ADR-0043 point 9). Daily rather than at boot:
            // under Octane "boot" is every worker start, which would make it
            // noise instead of a control.
            $schedule->command(ReportInvoiceRetention::class)->dailyAt('07:15');

            // The provider reconciliation #71 asks for, split by the two
            // questions it asks. Hourly for the pending one: a lost
            // notification leaves an order unpaid on every screen while the
            // money has moved, and a payer who has paid must not wait a day
            // to see it. Daily for the settled one (ADR-0057 point 7): a
            // chargeback is a fact that changes at most once, and re-reading
            // ninety days of settled payments hourly would spend one
            // provider call per settled payment per hour to learn nothing.
            // `withoutOverlapping()` because a brand with a long backlog
            // must not stack a second sweep's provider calls on top of the
            // first's — the two entries carry different command strings, so
            // they take different mutexes and never block each other.
            $schedule->command(ReconcilePayments::class, ['--pending'])->hourly()->withoutOverlapping();
            $schedule->command(ReconcilePayments::class, ['--settled'])->dailyAt('06:40')->withoutOverlapping();

            // ADR-0057 point 4's deadline sweep, in the morning beside the
            // dunning sweep it hands checked-in orders to. Daily because
            // `repay_due_on` is a date: a more frequent run would ask the
            // same question repeatedly to reach the same answer.
            $schedule->command(CancelUnpaidReversals::class)->dailyAt('07:05');

            // A cart's twenty-minute reservation (ADR-0047, Task 9) needs a
            // sweep on the same cadence as the waitlist offer expiry it
            // mirrors — fifteen minutes is frequent enough that stock a
            // person abandoned comes back well within a rush.
            // `withoutOverlapping()` (Task 9 review minor): a rush that
            // makes one run take longer than fifteen minutes must not stack
            // a second run on top of it.
            $schedule->command('commerce:expire-carts')->everyFifteenMinutes()->withoutOverlapping();

            // The waiting room's own heartbeat (ADR-0047 point 5, Task 13):
            // every minute, stagger up to twelve admission batches per busy
            // brand across that minute. `withoutOverlapping()` for the same
            // reason as the sweep above — a slow run must not stack a
            // second tick's dispatches on top of the first's.
            $schedule->command(WaitingRoomTick::class)->everyMinute()->withoutOverlapping();
        });
    }

    /**
     * The invoice mail (SPEC.md "Does not own" — sending is Messaging's;
     * this module only declares what its mail says). German default: the
     * release targets domestic DACH events (ADR-0030's framing), and the
     * billing party's locale is unknown for account-less recipients.
     *
     * ADR-0048: two template keys, not one branching on `$paid` — a per-
     * brand override of either wording (`TemplateOverride`) then stays a
     * plain template with no conditional of its own. `commerce.invoice-issued`
     * stays the open-invoice wording exactly as before; `commerce.invoice-paid`
     * is the ADR-0048 paid wording. `SendInvoiceMail` picks the key from
     * `Invoice::isPaid()`. See `.ai/rules/app-modules-src.md` for why a
     * template string branching on `@if($paid)…@else…@endif` is fragile
     * regardless: `TemplateRenderer` runs both subject and body through
     * `Blade::render()`, and Blade only recognises a directive glued
     * directly to the preceding/following text as literal words, not a
     * directive boundary.
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
            sample: [
                'recipientName' => 'Erika Musterfrau',
                'invoiceNumber' => 'RE-2026-0142',
                'totalGross' => '35.70',
                'currency' => 'EUR',
                'dueOn' => '28.08.2026',
                'sellerName' => 'Konventix Demo GmbH',
            ],
        ));

        $this->app->make(TemplateRegistry::class)->register(new MessageTemplate(
            key: 'commerce.invoice-paid',
            subjects: [
                'de' => 'Ihre Rechnung {{ $invoiceNumber }} – bezahlt',
                'en' => 'Your invoice {{ $invoiceNumber }} – paid',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Guten Tag {{ $recipientName }},</p>
                    <p>vielen Dank für Ihre Zahlung über <strong>{{ $totalGross }} {{ $currency }}</strong>
                    am <strong>{{ $paidAt }}</strong> via <strong>{{ $paidVia }}</strong>.</p>
                    <p>Anbei erhalten Sie die Rechnung <strong>{{ $invoiceNumber }}</strong> als Beleg.</p>
                    <p>Mit freundlichen Grüßen<br>{{ $sellerName }}</p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Dear {{ $recipientName }},</p>
                    <p>thank you for your payment of <strong>{{ $totalGross }} {{ $currency }}</strong>
                    on <strong>{{ $paidAt }}</strong> via <strong>{{ $paidVia }}</strong>.</p>
                    <p>Please find attached invoice <strong>{{ $invoiceNumber }}</strong> as your receipt.</p>
                    <p>Kind regards<br>{{ $sellerName }}</p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'recipientName' => 'Erika Musterfrau',
                'invoiceNumber' => 'RE-2026-0142',
                'totalGross' => '35.70',
                'currency' => 'EUR',
                'paidAt' => '28.08.2026',
                'paidVia' => 'Kreditkarte',
                'sellerName' => 'Konventix Demo GmbH',
            ],
        ));

        // ADR-0048 point 2: a Gutschrift gets a document and a mail, like an
        // invoice. One template covers both reasons (refund/transfer) —
        // `reasonLabelDe`/`reasonLabelEn` carry both words already, so the
        // template body stays branch-free rather than gluing an
        // `@if($reasonCode === ...)` onto the strings this renders from
        // (`.ai/rules/app-modules-src.md`). The refund-specific sentence
        // about the original payment method is guarded on `invoiceNumber`'s
        // sibling condition instead: `SendCreditNoteMail` only ever sets
        // `reasonLabelDe`/`En` to the refund pair when the reason is a
        // refund, and the template checks that string, not a raw code.
        $this->app->make(TemplateRegistry::class)->register(new MessageTemplate(
            key: 'commerce.credit-note-issued',
            subjects: [
                'de' => 'Gutschrift {{ $creditNoteNumber }} von {{ $sellerName }}',
                'en' => 'Credit note {{ $creditNoteNumber }} from {{ $sellerName }}',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Guten Tag {{ $recipientName }},</p>
                    <p>anbei erhalten Sie die Gutschrift <strong>{{ $creditNoteNumber }}</strong>
                    über <strong>{{ $totalGross }} {{ $currency }}</strong> vom
                    <strong>{{ $issuedOn }}</strong>, Grund: <strong>{{ $reasonLabelDe }}</strong>.</p>
                    @if ($invoiceNumber)
                    <p>Die Gutschrift bezieht sich auf Rechnung <strong>{{ $invoiceNumber }}</strong>.</p>
                    @endif
                    @if ($reasonLabelDe === 'Erstattung')
                    <p>Der Betrag wird über den ursprünglichen Zahlungsweg erstattet; das
                    dauert je nach Anbieter einige Werktage.</p>
                    @endif
                    <p>Mit freundlichen Grüßen<br>{{ $sellerName }}</p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Dear {{ $recipientName }},</p>
                    <p>please find attached credit note <strong>{{ $creditNoteNumber }}</strong>
                    for <strong>{{ $totalGross }} {{ $currency }}</strong> dated
                    <strong>{{ $issuedOn }}</strong>, reason: <strong>{{ $reasonLabelEn }}</strong>.</p>
                    @if ($invoiceNumber)
                    <p>This credit note refers to invoice <strong>{{ $invoiceNumber }}</strong>.</p>
                    @endif
                    @if ($reasonLabelEn === 'Refund')
                    <p>The amount will be refunded via the original payment method; this
                    takes a few business days depending on the provider.</p>
                    @endif
                    <p>Kind regards<br>{{ $sellerName }}</p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'recipientName' => 'Erika Musterfrau',
                'creditNoteNumber' => 'GS-2026-0007',
                'totalGross' => '35.70',
                'currency' => 'EUR',
                'issuedOn' => '28.08.2026',
                'reasonLabelDe' => 'Erstattung',
                'reasonLabelEn' => 'Refund',
                'invoiceNumber' => 'RE-2026-0142',
                'sellerName' => 'Konventix Demo GmbH',
            ],
        ));

        // ADR-0048 point 3: a cart checkout's own confirmation — Registration
        // already tells a direct order's registrant their submission went
        // through, so this template exists for `settlesType: 'cart'` only
        // (`SendOrderConfirmationOnOrderPlaced`).
        $this->app->make(TemplateRegistry::class)->register(new MessageTemplate(
            key: 'commerce.order-confirmation',
            subjects: [
                'de' => 'Ihre Bestellung {{ $orderNumberShort }}',
                'en' => 'Your order {{ $orderNumberShort }}',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    @if ($recipientName)
                    <p>Guten Tag {{ $recipientName }},</p>
                    @else
                    <p>Guten Tag,</p>
                    @endif
                    <p>vielen Dank für Ihre Bestellung <strong>{{ $orderNumberShort }}</strong>
                    vom {{ $placedAt }}.</p>
                    <table>
                    <tbody>
                    @foreach ($lines as $line)
                    <tr>
                    <td>{{ $line['label'] }}</td>
                    <td>{{ $line['quantity'] }}×</td>
                    <td>{{ $line['lineGross'] }} {{ $currency }}</td>
                    </tr>
                    @endforeach
                    </tbody>
                    </table>
                    <p>Gesamt: <strong>{{ $totalGross }} {{ $currency }}</strong></p>
                    @if ($reservationUntil)
                    <p>Ihre Reservierung gilt bis {{ $reservationUntil }} — schließen Sie die
                    Zahlung bis dahin ab.</p>
                    @endif
                    <p><a href="{{ $ordersUrl }}">Meine Bestellungen</a></p>
                    BLADE,
                'en' => <<<'BLADE'
                    @if ($recipientName)
                    <p>Hello {{ $recipientName }},</p>
                    @else
                    <p>Hello,</p>
                    @endif
                    <p>thank you for your order <strong>{{ $orderNumberShort }}</strong>
                    placed on {{ $placedAt }}.</p>
                    <table>
                    <tbody>
                    @foreach ($lines as $line)
                    <tr>
                    <td>{{ $line['label'] }}</td>
                    <td>{{ $line['quantity'] }}×</td>
                    <td>{{ $line['lineGross'] }} {{ $currency }}</td>
                    </tr>
                    @endforeach
                    </tbody>
                    </table>
                    <p>Total: <strong>{{ $totalGross }} {{ $currency }}</strong></p>
                    @if ($reservationUntil)
                    <p>Your reservation holds until {{ $reservationUntil }} — please
                    complete payment before then.</p>
                    @endif
                    <p><a href="{{ $ordersUrl }}">My orders</a></p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'recipientName' => 'Erika Musterfrau',
                'orderNumberShort' => 'A1B2C3',
                'placedAt' => '28.08.2026',
                'lines' => [
                    ['label' => 'Tagesticket', 'quantity' => 2, 'lineGross' => '25.00'],
                ],
                'currency' => 'EUR',
                'totalGross' => '25.00',
                'reservationUntil' => '28.08.2026 18:00',
                'ordersUrl' => 'https://example.test/my/orders',
            ],
        ));

        // ADR-0048 point 3: a failed attempt explained to the payer, with a
        // link back to wherever they can try again
        // (`SendPaymentFailedMailOnPaymentFailed`).
        $this->app->make(TemplateRegistry::class)->register(new MessageTemplate(
            key: 'commerce.payment-failed',
            subjects: [
                'de' => 'Zahlung zu Bestellung {{ $orderNumberShort }} fehlgeschlagen',
                'en' => 'Payment for order {{ $orderNumberShort }} failed',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Guten Tag,</p>
                    <p>Ihre Zahlung zur Bestellung <strong>{{ $orderNumberShort }}</strong>
                    ist fehlgeschlagen. {{ $reason }}</p>
                    <p><a href="{{ $retryUrl }}">Zahlung erneut versuchen</a></p>
                    <p><a href="{{ $ordersUrl }}">Meine Bestellungen</a></p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Hello,</p>
                    <p>your payment for order <strong>{{ $orderNumberShort }}</strong>
                    failed. {{ $reason }}</p>
                    <p><a href="{{ $retryUrl }}">Try payment again</a></p>
                    <p><a href="{{ $ordersUrl }}">My orders</a></p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'orderNumberShort' => 'A1B2C3',
                'reason' => 'Die Karte wurde abgelehnt.',
                'retryUrl' => 'https://example.test/pay/A1B2C3',
                'ordersUrl' => 'https://example.test/my/orders',
            ],
        ));

        // ADR-0057 point 8: money that had settled left again, explained to
        // the payer with the deadline of point 4 and the link back to paying
        // (`SendPaymentReversedMailOnPaymentReversed`). `reasonDe`/`reasonEn`
        // arrive as finished sentences, so this template branches on nothing
        // — the raw bank or network code never reaches the payer, because
        // "the mail informs, it does not accuse".
        $this->app->make(TemplateRegistry::class)->register(new MessageTemplate(
            key: 'commerce.payment-reversed',
            subjects: [
                'de' => 'Zahlung zu Bestellung {{ $orderNumberShort }} wurde zurückgebucht',
                'en' => 'Payment for order {{ $orderNumberShort }} was reversed',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Guten Tag,</p>
                    <p>der Betrag von <strong>{{ $amount }} {{ $currency }}</strong> zur
                    Bestellung <strong>{{ $orderNumberShort }}</strong> wurde zurückgebucht.
                    {{ $reasonDe }}</p>
                    <p>Ihre Bestellung bleibt bestehen. Bitte gleichen Sie den Betrag bis zum
                    <strong>{{ $dueOn }}</strong> aus.</p>
                    <p><a href="{{ $payUrl }}">Jetzt bezahlen</a></p>
                    <p><a href="{{ $ordersUrl }}">Meine Bestellungen</a></p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Hello,</p>
                    <p>the amount of <strong>{{ $amount }} {{ $currency }}</strong> for order
                    <strong>{{ $orderNumberShort }}</strong> was reversed. {{ $reasonEn }}</p>
                    <p>Your order stands. Please settle the amount by
                    <strong>{{ $dueOn }}</strong>.</p>
                    <p><a href="{{ $payUrl }}">Pay now</a></p>
                    <p><a href="{{ $ordersUrl }}">My orders</a></p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'orderNumberShort' => 'A1B2C3',
                'amount' => '119.00',
                'currency' => 'EUR',
                'reasonDe' => 'Die Bank meldet, dass das Konto zum Zeitpunkt der Abbuchung nicht ausreichend gedeckt war.',
                'reasonEn' => 'The bank reports that the account did not have sufficient funds at the time of the debit.',
                'dueOn' => '14.09.2026',
                'payUrl' => 'https://example.test/pay/A1B2C3',
                'ordersUrl' => 'https://example.test/my/orders',
            ],
        ));

        // The same fact for an order that cannot be repaid online: a cart
        // order, because `CheckoutController` is addressed by registration
        // ULID and repaying a reversed cart is a follow-up. Its own key
        // rather than an `@if ($payUrl)` branch — a branch-free template is
        // what keeps a per-brand `TemplateOverride` branch-free too
        // (`.ai/rules/app-modules-src.md`), the same split
        // `commerce.invoice-issued`/`commerce.invoice-paid` draws.
        $this->app->make(TemplateRegistry::class)->register(new MessageTemplate(
            key: 'commerce.payment-reversed-manual',
            subjects: [
                'de' => 'Zahlung zu Bestellung {{ $orderNumberShort }} wurde zurückgebucht',
                'en' => 'Payment for order {{ $orderNumberShort }} was reversed',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Guten Tag,</p>
                    <p>der Betrag von <strong>{{ $amount }} {{ $currency }}</strong> zur
                    Bestellung <strong>{{ $orderNumberShort }}</strong> wurde zurückgebucht.
                    {{ $reasonDe }}</p>
                    <p>Ihre Bestellung bleibt bestehen; der Betrag ist bis zum
                    <strong>{{ $dueOn }}</strong> erneut offen. Eine Online-Zahlung ist für
                    diese Bestellung derzeit nicht möglich — der Veranstalter kann eine
                    Überweisung oder eine Zahlung vor Ort verbuchen.</p>
                    <p><a href="{{ $ordersUrl }}">Meine Bestellungen</a></p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Hello,</p>
                    <p>the amount of <strong>{{ $amount }} {{ $currency }}</strong> for order
                    <strong>{{ $orderNumberShort }}</strong> was reversed. {{ $reasonEn }}</p>
                    <p>Your order stands; the amount is owed again by
                    <strong>{{ $dueOn }}</strong>. Paying online is not available for this
                    order at the moment — the organizer can book a bank transfer or a
                    payment at the door.</p>
                    <p><a href="{{ $ordersUrl }}">My orders</a></p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'orderNumberShort' => 'A1B2C3',
                'amount' => '119.00',
                'currency' => 'EUR',
                'reasonDe' => 'Die Bank meldet, dass das Konto zum Zeitpunkt der Abbuchung nicht ausreichend gedeckt war.',
                'reasonEn' => 'The bank reports that the account did not have sufficient funds at the time of the debit.',
                'dueOn' => '14.09.2026',
                'ordersUrl' => 'https://example.test/my/orders',
            ],
        ));

        // ADR-0048 point 3: one template for every shop-operator notice on
        // the `commerce.operations` topic — `title`/`detail`/`url` are
        // already composed by the sending listener
        // (`NotifyOperatorsOnStockExhausted`, `NotifyOperatorsOnPaymentFailed`,
        // `NotifyOperatorsOnPaymentReversalRecorded`,
        // `NotifyOperatorsOnPaymentUnapplied`, and `ReconcilePayments`), so
        // this stays branch-free regardless of `kind` — ADR-0057 point 8's
        // new kinds needed no template change at all.
        $this->app->make(TemplateRegistry::class)->register(new MessageTemplate(
            key: 'commerce.operations-notice',
            subjects: [
                'de' => '{{ $title }}',
                'en' => '{{ $title }}',
            ],
            bodies: [
                'de' => '<p>{{ $detail }}</p><p><a href="{{ $url }}">Im Konsolenbereich öffnen</a></p>',
                'en' => '<p>{{ $detail }}</p><p><a href="{{ $url }}">Open in the console</a></p>',
            ],
            defaultLocale: 'de',
            sample: [
                'title' => 'Lager leer: Tagesticket',
                'detail' => 'Das Kontingent für Tagesticket ist ausverkauft.',
                'url' => 'https://example.test/admin/products',
            ],
        ));
    }

    /**
     * ADR-0048 point 3: the shop operators' own topic — a sold-out variant
     * or a failed payment, in-app and by mail, both suppressible (an
     * operator may prefer just one channel) and digestable, declared here
     * for future non-urgent operational sends on it. Today's two triggers
     * (`NotifyOperatorsOnStockExhausted`, `NotifyOperatorsOnPaymentFailed`)
     * both set `urgent: true` on the request itself — a manager is worth an
     * immediate nudge, not a 30-minute-coalesced digest, the same
     * `HoldPolicy`/D-CNT-08 reasoning an urgent announcement already uses.
     */
    protected function registerMessageTopics(): void
    {
        $this->app->make(TopicRegistry::class)->register(new Topic(
            key: 'commerce.operations',
            label: 'Shop operations',
            defaultChannels: [Channel::InApp, Channel::Mail],
            suppressible: true,
            digestable: true,
        ));
    }

    /**
     * What a registration cost and whether it is settled, contributed to
     * Registration's own account page.
     *
     * Asked about one registration whose ownership the caller has already
     * established, Commerce answers completely; the standalone list lives at
     * `/my/orders` and reads `payer_user_id` (ADR-0044).
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

        // The shop's contribution to Event's `event.landing` slot (ADR-0047,
        // Task 8): what a visitor can buy for this edition, resolved
        // server-side the same way Registration's `EventOffers` is, so the
        // call to action is in the first paint rather than a fetch behind
        // it. `static` for the reason above — this registry outlives the
        // request under Octane. Renders nothing when the brand has no
        // products for this event, exactly like `EventOffers` does for no
        // public types.
        $this->app->make(SlotRegistry::class)->add(
            slot: 'event.landing',
            module: 'commerce',
            component: 'commerce/ShopLandingCard',
            props: static function (array $context): array {
                $products = Product::query()
                    ->published()
                    ->where('event_id', $context['eventId'])
                    ->with('variants')
                    ->orderBy('name')
                    ->get();

                $media = app(MediaLibrary::class);

                return [
                    'products' => $products
                        ->map(fn (Product $product): array => ShopProductData::ofModel(
                            $product,
                            $product->image_media_id === null ? null : $media->urlFor($product->image_media_id),
                        )->toArray())
                        ->all(),
                ];
            },
            feature: 'commerce:shop',
            order: 20,
        );
    }

    protected function registerNavigation(): void
    {
        $registry = $this->app->make(NavigationRegistry::class);

        // The brand topbar's own entry (ADR-0047): gated on the feature, not
        // a permission — anyone browsing the brand's own domain may shop,
        // whether signed in or not, so this is visible or not purely on
        // whether the brand switched `commerce:shop` on.
        $registry->add(
            new NavigationItem(
                module: 'commerce',
                label: 'Shop',
                url: '/shop',
                icon: 'shopping-bag',
                order: 30,
                area: NavigationItem::AREA_BRAND,
                feature: 'commerce:shop',
            ),
        );

        // The cart (ADR-0047, Task 11) — right after "Shop", gated on the
        // same feature. `HandleInertiaRequests`' `cart.count` prop is what
        // badges this entry; the item itself is visible or not purely on
        // whether the brand switched shopping on, the same as "Shop".
        $registry->add(
            new NavigationItem(
                module: 'commerce',
                label: 'Cart',
                url: '/cart',
                icon: 'shopping-cart',
                order: 31,
                area: NavigationItem::AREA_BRAND,
                feature: 'commerce:shop',
            ),
        );

        $registry->add(
            new NavigationItem(
                module: 'commerce',
                label: 'Products',
                url: '/admin/products',
                icon: 'shopping-bag',
                order: 24,
                permission: Permission::ViewProducts->value,
                area: NavigationItem::AREA_ADMIN,
            ),
        );

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

        // The account menu (ADR-0044). No `permission`, and none is missing:
        // the menu renders only for a signed-in user and the controller
        // authorizes on ownership, which is not a capability anybody holds.
        $registry->add(
            new NavigationItem(
                module: 'commerce',
                label: 'My orders',
                url: '/my/orders',
                icon: 'receipt',
                order: 20,
                area: NavigationItem::AREA_ACCOUNT,
            ),
        );
    }
}

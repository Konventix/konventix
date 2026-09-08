<?php

declare(strict_types=1);

namespace Konventix\Registration\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Konventix\Commerce\Events\CartLineExpired;
use Konventix\Commerce\Events\CartLineRemoved;
use Konventix\Commerce\Events\InvoiceIssued;
use Konventix\Commerce\Events\OrderCancelled;
use Konventix\Commerce\Events\OrderLineCancelled;
use Konventix\Commerce\Events\OrderLineReversed;
use Konventix\Commerce\Events\OrderLineSettled;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\OrderRefunded;
use Konventix\Commerce\Events\PaymentReversed;
use Konventix\Commerce\Events\RegistrationLinePlacedInCart;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Event\Events\EventArchived;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Permissions\DefaultRole;
use Konventix\Platform\Slots\SlotRegistry;
use Konventix\Registration\Console\ExpireWaitlistOffersCommand;
use Konventix\Registration\Data\PublicOfferData;
use Konventix\Registration\Enums\Permission;
use Konventix\Registration\Enums\RegistrationVisibility;
use Konventix\Registration\Events\QuotaReleased;
use Konventix\Registration\Events\RegistrationApproved;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationRejected;
use Konventix\Registration\Events\RegistrationSubmitted;
use Konventix\Registration\Gdpr\RegistrationGdprHandler;
use Konventix\Registration\Listeners\CancelPendingRegistrationOnCartLineGone;
use Konventix\Registration\Listeners\CancelRegistrationOnOrderLineCancelled;
use Konventix\Registration\Listeners\CancelRegistrationOnOrderUndone;
use Konventix\Registration\Listeners\MailOnRegistrationApproved;
use Konventix\Registration\Listeners\MailOnRegistrationCancelled;
use Konventix\Registration\Listeners\MailOnRegistrationConfirmed;
use Konventix\Registration\Listeners\MailOnRegistrationRejected;
use Konventix\Registration\Listeners\MailOnRegistrationSubmitted;
use Konventix\Registration\Listeners\MarkInvoicedOnInvoiceIssued;
use Konventix\Registration\Listeners\ProjectEventSummary;
use Konventix\Registration\Listeners\ProjectRegistrant;
use Konventix\Registration\Listeners\PromoteOnQuotaReleased;
use Konventix\Registration\Listeners\RecordCartHoldOnLinePlaced;
use Konventix\Registration\Listeners\RecordPaymentOnOrderLineSettled;
use Konventix\Registration\Listeners\RecordPaymentOnOrderPaid;
use Konventix\Registration\Listeners\RecordReversalOnOrderLineReversed;
use Konventix\Registration\Listeners\RecordReversalOnPaymentReversed;
use Konventix\Registration\Models\RegistrationType;

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
     * @return list<DefaultRole>
     */
    public function defaultRoles(): array
    {
        return [
            DefaultRole::of(
                'registration.desk',
                'Registration Desk',
                Permission::ViewRegistrations,
                Permission::ApproveRegistrations,
                Permission::RejectRegistrations,
                Permission::CancelRegistrations,
                Permission::ExportRegistrations,
            ),
        ];
    }

    /**
     * Registration learns payment outcomes from Commerce's events, never
     * from Commerce models (ADR-0006, SPEC.md "Consumes").
     */
    protected function registerListeners(): void
    {
        Event::listen(OrderPaid::class, RecordPaymentOnOrderPaid::class);

        // ADR-0047 point 4 (Task 11): the counterpart for a cart order,
        // whose lines `OrderPaid` alone cannot tell apart.
        Event::listen(OrderLineSettled::class, RecordPaymentOnOrderLineSettled::class);

        // ADR-0057 point 5: a settled payment that left again revives the
        // receivable — `payment_status` back to pending — while the seat and
        // its quota stay held until the repayment deadline. The per-line twin
        // is a cart order's, exactly as with `OrderLineSettled`.
        Event::listen(PaymentReversed::class, RecordReversalOnPaymentReversed::class);
        Event::listen(OrderLineReversed::class, RecordReversalOnOrderLineReversed::class);

        // ADR-0057 point 5: the order no longer stands at all — the deadline
        // passed (`reversal_unpaid`) or the whole order was refunded — so the
        // contractual act ends too, releasing the quota to the waitlist.
        Event::listen(OrderCancelled::class, CancelRegistrationOnOrderUndone::class);
        Event::listen(OrderRefunded::class, CancelRegistrationOnOrderUndone::class);

        // The per-line twin, for a cart order the sweep cancelled: the
        // order-level event cannot say which of its lines to cancel, exactly
        // as `OrderPaid` cannot say which of them to confirm.
        Event::listen(OrderLineCancelled::class, CancelRegistrationOnOrderLineCancelled::class);

        // D-REG-12: a freed quota unit promotes the oldest queued waitlist
        // entry — including units released by expired offers, which is what
        // makes the queue drain itself.
        Event::listen(QuotaReleased::class, PromoteOnQuotaReleased::class);

        // ADR-0036: billing party freezes at first invoice.
        Event::listen(InvoiceIssued::class, MarkInvoicedOnInvoiceIssued::class);

        // #210: the sibling on creation — a settlement that went into the
        // cart instead of into an order, so the confirmation mail can name
        // the cart and its hold without reading Commerce's tables.
        Event::listen(RegistrationLinePlacedInCart::class, RecordCartHoldOnLinePlaced::class);

        // ADR-0047 point 3: a cart line that dies — expired or removed —
        // cancels the still-pending registration it was holding.
        Event::listen(CartLineExpired::class, CancelPendingRegistrationOnCartLineGone::class);
        Event::listen(CartLineRemoved::class, CancelPendingRegistrationOnCartLineGone::class);

        // Registrant read model for organizer lists (ADR-0004).
        Event::listen(UserRegistered::class, ProjectRegistrant::class);
        Event::listen(UserProfileUpdated::class, ProjectRegistrant::class);

        // Event read model, so a type is created against an event picked by
        // name rather than by pasted ULID (ADR-0004).
        Event::listen(EventCreated::class, [ProjectEventSummary::class, 'handleCreated']);
        Event::listen(EventDetailsUpdated::class, [ProjectEventSummary::class, 'handleDetailsUpdated']);
        Event::listen(EventArchived::class, [ProjectEventSummary::class, 'handleArchived']);

        // ADR-0048 point 3: the module that owns an event owns its mail —
        // Registration's own lifecycle, told to the registrant every time
        // its state changes.
        Event::listen(RegistrationSubmitted::class, MailOnRegistrationSubmitted::class);
        Event::listen(RegistrationApproved::class, MailOnRegistrationApproved::class);
        Event::listen(RegistrationRejected::class, MailOnRegistrationRejected::class);
        Event::listen(RegistrationConfirmed::class, MailOnRegistrationConfirmed::class);
        Event::listen(RegistrationCancelled::class, MailOnRegistrationCancelled::class);
    }

    protected function registerGdprExporters(): void
    {
        $this->app->make(GdprHandlerRegistry::class)->register(new RegistrationGdprHandler);
    }

    protected function registerNavigation(): void
    {
        $this->app->make(NavigationRegistry::class)->add(
            // The registrant's own, in the account menu. No `permission`,
            // and none is missing: the menu renders only for a signed-in
            // user and the controller authorizes on ownership, which is not
            // a capability anybody can be granted.
            new NavigationItem(
                module: 'registration',
                label: 'My registrations',
                url: '/my/registrations',
                icon: 'ticket',
                order: 10,
                area: NavigationItem::AREA_ACCOUNT,
            ),
            new NavigationItem(
                module: 'registration',
                label: 'Registrations',
                url: '/admin/registrations',
                icon: 'clipboard-list',
                order: 20,
                permission: Permission::ViewRegistrations->value,
                area: NavigationItem::AREA_ADMIN,
            ),
            new NavigationItem(
                module: 'registration',
                label: 'Registration types',
                url: '/admin/registration-types',
                icon: 'ticket',
                order: 21,
                permission: Permission::ManageRegistrationTypes->value,
                area: NavigationItem::AREA_ADMIN,
            ),
            new NavigationItem(
                module: 'registration',
                label: 'Quotas',
                url: '/admin/quotas',
                icon: 'gauge',
                order: 22,
                permission: Permission::ManageQuotas->value,
                area: NavigationItem::AREA_ADMIN,
            ),
        );
    }

    /**
     * The waitlist-offer mail (D-REG-12) plus the five lifecycle mails
     * (ADR-0048 point 3); sending is Messaging's concern, this module only
     * declares what its mail says.
     */
    protected function registerMessageTemplates(): void
    {
        $registry = $this->app->make(TemplateRegistry::class);

        $registry->register(new MessageTemplate(
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
            sample: [
                'claimUrl' => 'https://example.test/waitlist/claim/abc123',
                'offerExpiresAt' => '28.08.2026 18:00',
            ],
        ));

        // ADR-0048 point 3: Registration's own lifecycle mail — one key per
        // event, `Data\RegistrationMailData` the shared payload. `nextStep`
        // and `cancelReason` branch with whitespace-safe `@if`/`@elseif`
        // (`.ai/rules/app-modules-src.md`) — every directive on its own
        // line — since the wording each names is inherently locale-specific
        // and cannot be precomputed in PHP.
        $registry->register(new MessageTemplate(
            key: 'registration.submitted',
            subjects: [
                'de' => 'Ihre Anmeldung ist eingegangen',
                'en' => 'Your registration has been received',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    @if ($holderName)
                    <p>Guten Tag {{ $holderName }},</p>
                    @else
                    <p>Guten Tag,</p>
                    @endif
                    @if ($eventName)
                    <p>Ihre Anmeldung zu {{ $eventName }} ({{ $registrationTypeName }}) ist bei uns eingegangen.</p>
                    @else
                    <p>Ihre Anmeldung ({{ $registrationTypeName }}) ist bei uns eingegangen.</p>
                    @endif
                    @if ($nextStep === 'approval')
                    <p>Wir prüfen Ihre Anmeldung und melden uns.</p>
                    @elseif ($nextStep === 'pay')
                    <p>Bitte schließen Sie die Zahlung ab — der Link ist auf Ihrer <a href="{{ $registrationUrl }}">Anmeldeseite</a>.</p>
                    @elseif ($nextStep === 'invoice')
                    <p>Sie erhalten eine Rechnung per E-Mail.</p>
                    @else
                    <p>Ihre Anmeldung ist bestätigt.</p>
                    @endif
                    <p><a href="{{ $registrationUrl }}">Zu Ihrer Anmeldung</a></p>
                    BLADE,
                'en' => <<<'BLADE'
                    @if ($holderName)
                    <p>Hello {{ $holderName }},</p>
                    @else
                    <p>Hello,</p>
                    @endif
                    @if ($eventName)
                    <p>Your registration for {{ $eventName }} ({{ $registrationTypeName }}) has been received.</p>
                    @else
                    <p>Your registration ({{ $registrationTypeName }}) has been received.</p>
                    @endif
                    @if ($nextStep === 'approval')
                    <p>We are reviewing your registration and will get back to you.</p>
                    @elseif ($nextStep === 'pay')
                    <p>Please complete the payment — the link is on your <a href="{{ $registrationUrl }}">registration page</a>.</p>
                    @elseif ($nextStep === 'invoice')
                    <p>You will receive an invoice by email.</p>
                    @else
                    <p>Your registration is confirmed.</p>
                    @endif
                    <p><a href="{{ $registrationUrl }}">Go to your registration</a></p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'holderName' => 'Erika Musterfrau',
                'eventName' => 'SXLan 2026',
                'registrationTypeName' => 'Teilnehmer-Ticket',
                'nextStep' => 'pay',
                'registrationUrl' => 'https://example.test/my/registrations/abc123',
            ],
        ));

        $registry->register(new MessageTemplate(
            key: 'registration.approved',
            subjects: [
                'de' => 'Ihre Anmeldung wurde angenommen',
                'en' => 'Your registration has been accepted',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    @if ($holderName)
                    <p>Guten Tag {{ $holderName }},</p>
                    @else
                    <p>Guten Tag,</p>
                    @endif
                    @if ($eventName)
                    <p>Ihre Anmeldung zu {{ $eventName }} ({{ $registrationTypeName }}) wurde angenommen.</p>
                    @else
                    <p>Ihre Anmeldung ({{ $registrationTypeName }}) wurde angenommen.</p>
                    @endif
                    @if ($nextStep === 'approval')
                    <p>Wir prüfen Ihre Anmeldung und melden uns.</p>
                    @elseif ($nextStep === 'pay')
                    <p>Bitte schließen Sie die Zahlung ab — der Link ist auf Ihrer <a href="{{ $registrationUrl }}">Anmeldeseite</a>.</p>
                    @elseif ($nextStep === 'invoice')
                    <p>Sie erhalten eine Rechnung per E-Mail.</p>
                    @else
                    <p>Ihre Anmeldung ist bestätigt.</p>
                    @endif
                    <p><a href="{{ $registrationUrl }}">Zu Ihrer Anmeldung</a></p>
                    BLADE,
                'en' => <<<'BLADE'
                    @if ($holderName)
                    <p>Hello {{ $holderName }},</p>
                    @else
                    <p>Hello,</p>
                    @endif
                    @if ($eventName)
                    <p>Your registration for {{ $eventName }} ({{ $registrationTypeName }}) has been accepted.</p>
                    @else
                    <p>Your registration ({{ $registrationTypeName }}) has been accepted.</p>
                    @endif
                    @if ($nextStep === 'approval')
                    <p>We are reviewing your registration and will get back to you.</p>
                    @elseif ($nextStep === 'pay')
                    <p>Please complete the payment — the link is on your <a href="{{ $registrationUrl }}">registration page</a>.</p>
                    @elseif ($nextStep === 'invoice')
                    <p>You will receive an invoice by email.</p>
                    @else
                    <p>Your registration is confirmed.</p>
                    @endif
                    <p><a href="{{ $registrationUrl }}">Go to your registration</a></p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'holderName' => 'Erika Musterfrau',
                'eventName' => 'SXLan 2026',
                'registrationTypeName' => 'Teilnehmer-Ticket',
                'nextStep' => 'pay',
                'registrationUrl' => 'https://example.test/my/registrations/abc123',
            ],
        ));

        $registry->register(new MessageTemplate(
            key: 'registration.rejected',
            subjects: [
                'de' => 'Ihre Anmeldung wurde abgelehnt',
                'en' => 'Your registration has been declined',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    @if ($holderName)
                    <p>Guten Tag {{ $holderName }},</p>
                    @else
                    <p>Guten Tag,</p>
                    @endif
                    @if ($eventName)
                    <p>Ihre Anmeldung zu {{ $eventName }} ({{ $registrationTypeName }}) wurde leider abgelehnt.</p>
                    @else
                    <p>Ihre Anmeldung ({{ $registrationTypeName }}) wurde leider abgelehnt.</p>
                    @endif
                    @if ($reason === 'capacity')
                    <p>Grund: Die Kapazität war ausgeschöpft.</p>
                    @elseif ($reason === 'incomplete')
                    <p>Grund: Ihre Angaben waren unvollständig.</p>
                    @elseif ($reason === 'duplicate')
                    <p>Grund: Es liegt bereits eine Anmeldung von Ihnen vor.</p>
                    @else
                    <p>Grund: {{ $reason }}</p>
                    @endif
                    BLADE,
                'en' => <<<'BLADE'
                    @if ($holderName)
                    <p>Hello {{ $holderName }},</p>
                    @else
                    <p>Hello,</p>
                    @endif
                    @if ($eventName)
                    <p>Your registration for {{ $eventName }} ({{ $registrationTypeName }}) has unfortunately been declined.</p>
                    @else
                    <p>Your registration ({{ $registrationTypeName }}) has unfortunately been declined.</p>
                    @endif
                    @if ($reason === 'capacity')
                    <p>Reason: Capacity was exhausted.</p>
                    @elseif ($reason === 'incomplete')
                    <p>Reason: Your details were incomplete.</p>
                    @elseif ($reason === 'duplicate')
                    <p>Reason: A registration from you already exists.</p>
                    @else
                    <p>Reason: {{ $reason }}</p>
                    @endif
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'holderName' => 'Erika Musterfrau',
                'eventName' => 'SXLan 2026',
                'registrationTypeName' => 'Teilnehmer-Ticket',
                'reason' => 'capacity',
            ],
        ));

        $registry->register(new MessageTemplate(
            key: 'registration.confirmed',
            subjects: [
                'de' => 'Bestätigt: {{ $registrationTypeName }}',
                'en' => 'Confirmed: {{ $registrationTypeName }}',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    @if ($holderName)
                    <p>Guten Tag {{ $holderName }},</p>
                    @else
                    <p>Guten Tag,</p>
                    @endif
                    @if ($eventName)
                    <p>Ihre Anmeldung zu {{ $eventName }} ({{ $registrationTypeName }}) ist bestätigt.</p>
                    @else
                    <p>Ihre Anmeldung ({{ $registrationTypeName }}) ist bestätigt.</p>
                    @endif
                    @isset($cartUrl)
                    <p>Die Zahlung läuft über Ihren <a href="{{ $cartUrl }}">Warenkorb</a>. Ihr Platz ist dort bis {{ $cartHoldExpiresAt }} reserviert — danach wird die Reservierung freigegeben.</p>
                    @endisset
                    <p>Alles Weitere (Einlass, Unterlagen) finden Sie auf Ihrer <a href="{{ $registrationUrl }}">Anmeldeseite</a>.</p>
                    BLADE,
                'en' => <<<'BLADE'
                    @if ($holderName)
                    <p>Hello {{ $holderName }},</p>
                    @else
                    <p>Hello,</p>
                    @endif
                    @if ($eventName)
                    <p>Your registration for {{ $eventName }} ({{ $registrationTypeName }}) is confirmed.</p>
                    @else
                    <p>Your registration ({{ $registrationTypeName }}) is confirmed.</p>
                    @endif
                    @isset($cartUrl)
                    <p>Payment goes through your <a href="{{ $cartUrl }}">cart</a>. Your place is reserved there until {{ $cartHoldExpiresAt }} — after that the reservation is released.</p>
                    @endisset
                    <p>Everything else (admission, documents) is on your <a href="{{ $registrationUrl }}">registration page</a>.</p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'holderName' => 'Erika Musterfrau',
                'eventName' => 'SXLan 2026',
                'registrationTypeName' => 'Teilnehmer-Ticket',
                'registrationUrl' => 'https://example.test/my/registrations/abc123',
                'cartUrl' => 'https://example.test/cart',
                'cartHoldExpiresAt' => '08.09.2026 00:30 UTC',
            ],
        ));

        $registry->register(new MessageTemplate(
            key: 'registration.cancelled',
            subjects: [
                'de' => 'Ihre Anmeldung wurde storniert',
                'en' => 'Your registration has been cancelled',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    @if ($holderName)
                    <p>Guten Tag {{ $holderName }},</p>
                    @else
                    <p>Guten Tag,</p>
                    @endif
                    @if ($eventName)
                    <p>Ihre Anmeldung zu {{ $eventName }} ({{ $registrationTypeName }}) wurde storniert.</p>
                    @else
                    <p>Ihre Anmeldung ({{ $registrationTypeName }}) wurde storniert.</p>
                    @endif
                    @if ($cancelReason === 'cart_expired')
                    <p>Ihre Reservierung ist abgelaufen, bevor die Zahlung einging.</p>
                    @elseif ($cancelReason === 'registrant')
                    <p>Die Stornierung erfolgte auf Ihren Wunsch.</p>
                    @else
                    <p>Die Stornierung erfolgte durch den Veranstalter.</p>
                    @endif
                    @if ($wasPaid)
                    <p>Eine bereits geleistete Zahlung wird erstattet; Sie erhalten dazu eine Gutschrift.</p>
                    @endif
                    BLADE,
                'en' => <<<'BLADE'
                    @if ($holderName)
                    <p>Hello {{ $holderName }},</p>
                    @else
                    <p>Hello,</p>
                    @endif
                    @if ($eventName)
                    <p>Your registration for {{ $eventName }} ({{ $registrationTypeName }}) has been cancelled.</p>
                    @else
                    <p>Your registration ({{ $registrationTypeName }}) has been cancelled.</p>
                    @endif
                    @if ($cancelReason === 'cart_expired')
                    <p>Your reservation expired before payment was received.</p>
                    @elseif ($cancelReason === 'registrant')
                    <p>The cancellation was made at your request.</p>
                    @else
                    <p>The cancellation was made by the organizer.</p>
                    @endif
                    @if ($wasPaid)
                    <p>Any payment already made will be refunded; you will receive a credit note for it.</p>
                    @endif
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'holderName' => 'Erika Musterfrau',
                'eventName' => 'SXLan 2026',
                'registrationTypeName' => 'Teilnehmer-Ticket',
                'cancelReason' => 'registrant',
                'wasPaid' => true,
            ],
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
     * Two contributions to Event's UI slots (ADR-0003 enforcement point 3,
     * docs/decisions/0003). Event never queries this module's models in
     * either case — it hands over a slot and a context, and what comes back
     * is Registration's business.
     *
     * They differ in how they get their data, because their pages differ.
     * The admin card fetches its own numbers client-side, so an organizer
     * can refresh them without reloading. The landing page's offers are
     * resolved server-side through the closure prop-bag: it is the call to
     * action on a public page, and a call to action that appears one network
     * round trip after the page is not one.
     *
     * The closure is `static` deliberately — `SlotRegistry` is a singleton
     * that outlives the request under Octane, and a closure capturing `$this`
     * would pin this provider in memory with it.
     */
    protected function registerSlots(): void
    {
        $slots = $this->app->make(SlotRegistry::class);

        $slots->add(
            slot: 'event.admin.dashboard',
            module: 'registration',
            component: 'registration/EventStatsCard',
            props: [],
            order: 10,
        );

        $slots->add(
            slot: 'event.landing',
            module: 'registration',
            component: 'registration/EventOffers',
            props: static fn (array $context): array => [
                'offers' => RegistrationType::query()
                    // Only `Public` types: `Link` and `InviteOnly` are
                    // reachable with a token and must not be advertised to
                    // someone who does not hold one (D-COM-02).
                    ->where('event_id', $context['eventId'])
                    ->where('visibility', RegistrationVisibility::Public)
                    // #218: a sale that is over drops off the page entirely —
                    // linking to a form `SubmitRegistration` now refuses is
                    // worse than saying nothing. A sale that has not started
                    // stays, announced rather than linked, so the page can
                    // carry the "sale starts" date a visitor came for. The
                    // rule itself lives on the model, next to `isOnSale()`.
                    ->saleNotOver()
                    ->orderBy('name')
                    ->get()
                    ->map(fn (RegistrationType $type): array => PublicOfferData::ofType($type)->toArray())
                    ->all(),
            ],
            order: 10,
        );
    }
}

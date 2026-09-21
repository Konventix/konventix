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
use Konventix\Event\Events\EventAnnounced;
use Konventix\Event\Events\EventArchived;
use Konventix\Event\Events\EventAttendeeListPolicyChanged;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventPublished;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Events\VenueAssigned;
use Konventix\Event\Events\VenueUnassigned;
use Konventix\Event\Events\VenueUpdated;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Data\EndPriceData;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Pages\RegistrantAudience;
use Konventix\Platform\Permissions\DefaultRole;
use Konventix\Platform\Slots\SlotRegistry;
use Konventix\Registration\Audience\RegistrationRegistrantAudience;
use Konventix\Registration\Blocks\TicketsForEventBlock;
use Konventix\Registration\Console\AnnounceRegistrationOwnershipCommand;
use Konventix\Registration\Console\ExpireWaitlistOffersCommand;
use Konventix\Registration\Data\EventAttendeesData;
use Konventix\Registration\Data\PublicOfferData;
use Konventix\Registration\Enums\Permission;
use Konventix\Registration\Events\QuotaReleased;
use Konventix\Registration\Events\RegistrationApproved;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationQuantityReduced;
use Konventix\Registration\Events\RegistrationRejected;
use Konventix\Registration\Events\RegistrationSubmitted;
use Konventix\Registration\Gdpr\RegistrationGdprHandler;
use Konventix\Registration\Listeners\AdjustQuotaOnPlaceSupplyChanged;
use Konventix\Registration\Listeners\CancelPendingRegistrationOnCartLineGone;
use Konventix\Registration\Listeners\CancelRegistrationOnOrderLineCancelled;
use Konventix\Registration\Listeners\CancelRegistrationOnOrderUndone;
use Konventix\Registration\Listeners\MailOnRegistrationApproved;
use Konventix\Registration\Listeners\MailOnRegistrationCancelled;
use Konventix\Registration\Listeners\MailOnRegistrationConfirmed;
use Konventix\Registration\Listeners\MailOnRegistrationQuantityReduced;
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
use Konventix\Registration\Models\EventSummary;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Registration\Offers\PublicOffers;
use Konventix\Resources\Events\PlaceSupplyChanged;

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
     * Platform's `Pages\RegistrantAudience` contract (Task 20), the same
     * inversion as Commerce's `WaitingRoomGate` binding: Platform owns the
     * interface and asks it who counts as a registrant of a brand; this
     * module answers from its own `registrations` table and never hands a
     * model across. Platform resolves it lazily and fails closed without it.
     */
    protected function registerBindings(): void
    {
        $this->app->bind(RegistrantAudience::class, RegistrationRegistrantAudience::class);
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

        // Design §12b/#282: a place's bookability changed for a reason that
        // is not a sale (blocked, reserved, published…) — consume or
        // release the unit of whichever quota names that category.
        Event::listen(PlaceSupplyChanged::class, AdjustQuotaOnPlaceSupplyChanged::class);

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
        Event::listen(EventRescheduled::class, [ProjectEventSummary::class, 'handleRescheduled']);
        Event::listen(VenueAssigned::class, [ProjectEventSummary::class, 'handleVenueAssigned']);
        Event::listen(VenueUpdated::class, [ProjectEventSummary::class, 'handleVenueUpdated']);
        Event::listen(VenueUnassigned::class, [ProjectEventSummary::class, 'handleVenueUnassigned']);
        Event::listen(EventArchived::class, [ProjectEventSummary::class, 'handleArchived']);
        // ADR-0064 §6: the attendee-list switch, the only way this module
        // learns it — emitted at creation and on change, complete each time.
        Event::listen(EventAttendeeListPolicyChanged::class, [ProjectEventSummary::class, 'handleAttendeeListPolicyChanged']);
        // #240: `/shop` lists offers across every event, so the projection
        // has to know which events are public — `event.landing` gets that
        // from `Event::isVisibleOnLanding()`, which Registration may not
        // read. Both transitions out of `Draft`, and no listener for
        // `EventUnpublished`: unpublishing returns the event to `Announced`,
        // where the landing page still sells it (re-review N3).
        Event::listen(EventAnnounced::class, [ProjectEventSummary::class, 'handleAnnounced']);
        Event::listen(EventPublished::class, [ProjectEventSummary::class, 'handlePublished']);

        // ADR-0048 point 3: the module that owns an event owns its mail —
        // Registration's own lifecycle, told to the registrant every time
        // its state changes.
        Event::listen(RegistrationSubmitted::class, MailOnRegistrationSubmitted::class);
        Event::listen(RegistrationApproved::class, MailOnRegistrationApproved::class);
        Event::listen(RegistrationRejected::class, MailOnRegistrationRejected::class);
        Event::listen(RegistrationConfirmed::class, MailOnRegistrationConfirmed::class);
        Event::listen(RegistrationCancelled::class, MailOnRegistrationCancelled::class);

        // D-REG-15: the act got smaller rather than ending. Registered here
        // like the other five; Admission's and Commerce's listeners on the
        // same event live in their own providers and decide the ordering
        // between themselves (see `ReduceRegistrationQuantity`'s docblock).
        Event::listen(RegistrationQuantityReduced::class, MailOnRegistrationQuantityReduced::class);
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

        // D-REG-15: the sixth lifecycle mail. The act did not end — it got
        // smaller — so the body says what is left rather than what was
        // taken, and names the refund without promising a date: the money
        // goes back through Commerce's own queued refund.
        $registry->register(new MessageTemplate(
            key: 'registration.quantity-reduced',
            subjects: [
                'de' => 'Ihre Anmeldung wurde reduziert',
                'en' => 'Your registration has been reduced',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    @if ($holderName)
                    <p>Guten Tag {{ $holderName }},</p>
                    @else
                    <p>Guten Tag,</p>
                    @endif
                    @if ($eventName)
                    <p>Ihre Anmeldung zu {{ $eventName }} ({{ $registrationTypeName }}) wurde um {{ $removedUnits }} Einheit(en) reduziert.</p>
                    @else
                    <p>Ihre Anmeldung ({{ $registrationTypeName }}) wurde um {{ $removedUnits }} Einheit(en) reduziert.</p>
                    @endif
                    <p>Es bleiben {{ $newQuantity }} Einheit(en) gebucht.</p>
                    @if ($wasPaid)
                    <p>Der Betrag für die entfallenen Einheiten wird erstattet; Sie erhalten dazu eine Gutschrift.</p>
                    @endif
                    <p>Den aktuellen Stand sehen Sie auf Ihrer <a href="{{ $registrationUrl }}">Anmeldeseite</a>.</p>
                    BLADE,
                'en' => <<<'BLADE'
                    @if ($holderName)
                    <p>Hello {{ $holderName }},</p>
                    @else
                    <p>Hello,</p>
                    @endif
                    @if ($eventName)
                    <p>Your registration for {{ $eventName }} ({{ $registrationTypeName }}) has been reduced by {{ $removedUnits }} unit(s).</p>
                    @else
                    <p>Your registration ({{ $registrationTypeName }}) has been reduced by {{ $removedUnits }} unit(s).</p>
                    @endif
                    <p>{{ $newQuantity }} unit(s) remain booked.</p>
                    @if ($wasPaid)
                    <p>The amount for the removed units will be refunded; you will receive a credit note for it.</p>
                    @endif
                    <p>The current state is on your <a href="{{ $registrationUrl }}">registration page</a>.</p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'holderName' => 'Erika Musterfrau',
                'eventName' => 'SXLan 2026',
                'registrationTypeName' => 'Teilnehmer-Ticket',
                'registrationUrl' => 'https://example.test/my/registrations/abc123',
                'removedUnits' => 2,
                'newQuantity' => 1,
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

        $this->commands([ExpireWaitlistOffersCommand::class, AnnounceRegistrationOwnershipCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('registration:expire-waitlist-offers')->everyFifteenMinutes();
        });
    }

    /**
     * Registration's static-page block (bugfix round 2026-09-21, Task 7):
     * one event's public offers on any composed page. Same hook Platform's
     * own `text`/`hero`/`cards` blocks and Event's `event-hero` use, so
     * Platform never learns Registration exists (ADR-0003).
     */
    protected function registerBlockTypes(): void
    {
        $this->app->make(BlockRegistry::class)->register(new TicketsForEventBlock);
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
            // The rule itself — `Public` types only (D-COM-02), sale not
            // over (#218), by name — lives in `PublicOffers`, shared with
            // the `tickets-for-event` block so the two cannot drift.
            props: static fn (array $context): array => [
                'offers' => array_map(
                    fn (PublicOfferData $offer): array => $offer->toArray(),
                    PublicOffers::forEvent($context['eventId']),
                ),
            ],
            order: 10,
        );

        // Who is coming (ADR-0064), after the offers. The switch lives on
        // this module's `EventSummary` projection, not on Event's row; when
        // it is `off` the card gets `null` and renders nothing, and the
        // count and names are never computed. `auth()` is read here, at
        // request time, for the `members` half of the switch.
        $slots->add(
            slot: 'event.landing',
            module: 'registration',
            component: 'registration/EventAttendees',
            props: static function (array $context): array {
                $summary = EventSummary::query()->where('event_id', $context['eventId'])->first();

                if ($summary === null || ! $summary->showsAttendeeList()) {
                    return ['listing' => null];
                }

                return [
                    'listing' => EventAttendeesData::ofSummary($summary, viewerSignedIn: auth()->check())->toArray(),
                ];
            },
            order: 15,
        );

        // The shop's brand-wide counterpart (GitLab #240). `/shop` lists
        // what a brand sells with no event in the address, and until this
        // it listed goods only — a visitor who landed there saw the
        // merchandise and no way to register for anything, though ADR-0047
        // point 3 has a registration join the same cart.
        //
        // The same window rule as the landing slot, deliberately: one
        // module, one answer to "may this be bought now". The event's name
        // travels with each offer because "Weekend Ticket" on its own does
        // not say what it is a ticket to, and it comes from this module's
        // own `EventSummary` projection (ADR-0004).
        //
        // Unlike `event.landing`, this one needs its own visibility gate:
        // that slot cannot run for a hidden event at all, because
        // `EventLandingController` 404s on `Event::isVisibleOnLanding()`
        // long before it. `/shop` is addressed by no event, so it carries
        // the whole rule itself — and the rule a *listing* owes is the
        // stricter `Event::scopeListedPublicly()`: out of `Draft`
        // (`public_since`), `Public` rather than `Unlisted` (`visibility`),
        // and not archived. Each of the three was a real leak: next year's
        // unannounced convention, an invite-only track, last year's
        // edition.
        $slots->add(
            slot: 'shop.index',
            module: 'registration',
            component: 'registration/ShopOffers',
            props: static function (array $context): array {
                // One bounded read of the projection, and the *positive*
                // form of the filter (review finding 2): a subquery over
                // the summaries that are public and unarchived, rather than
                // a full-table read inlined as a growing literal
                // `NOT IN (…)`. It is also the only shape that can express
                // finding 1 — an event this module cannot name, or cannot
                // confirm is public, is exactly the one `/shop` must not
                // advertise.
                //
                // Blocker 2: a listing surface owes
                // `Event::scopeListedPublicly()`, not `isVisibleOnLanding()`
                // — an `Unlisted` event is reachable by its own link and
                // never enumerated. The rule is `EventSummary::scopeListedPublicly()`,
                // shared with the `tickets-for-event` block.
                $listable = EventSummary::query()->listedPublicly();

                // Name and slug together (Task 17c): the card links the
                // event's name to its page.
                $summaries = (clone $listable)->get(['event_id', 'name', 'slug'])->keyBy('event_id');

                /** @var list<RegistrationType> $types */
                $types = array_values(PublicOffers::query()
                    ->whereIn('event_id', (clone $listable)->select('event_id'))
                    ->get()
                    ->all());

                // What the offers actually cost (#234, D-COM-20). `/shop`
                // used to get its end prices from `ShopController`, which
                // filled `unitAmountGross` into this payload on the way out
                // — the reason it was the only public surface with one.
                // They are computed here now, through Platform's contract,
                // once for the whole list; the landing page and the
                // `tickets-for-event` block get theirs the same way, from
                // `PublicOffers::forEvent()`.
                $endPrices = PublicOffers::endPricesFor($types);

                return [
                    'offers' => array_map(
                        fn (RegistrationType $type, ?EndPriceData $endPrice): array => PublicOfferData::ofType(
                            $type,
                            // Never null here: the subquery above already
                            // dropped every type whose event has no listable
                            // summary (review finding 3).
                            (string) $summaries->get($type->event_id)?->name,
                            $summaries->get($type->event_id)?->slug,
                            $endPrice,
                        )->toArray(),
                        $types,
                        $endPrices,
                    ),
                ];
            },
            order: 10,
        );
    }
}

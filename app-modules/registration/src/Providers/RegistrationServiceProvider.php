<?php

declare(strict_types=1);

namespace Konventix\Registration\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Konventix\Commerce\Events\CartLineExpired;
use Konventix\Commerce\Events\CartLineRemoved;
use Konventix\Commerce\Events\InvoiceIssued;
use Konventix\Commerce\Events\OrderLineSettled;
use Konventix\Commerce\Events\OrderPaid;
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
use Konventix\Registration\Gdpr\RegistrationGdprHandler;
use Konventix\Registration\Listeners\CancelPendingRegistrationOnCartLineGone;
use Konventix\Registration\Listeners\MarkInvoicedOnInvoiceIssued;
use Konventix\Registration\Listeners\ProjectEventSummary;
use Konventix\Registration\Listeners\ProjectRegistrant;
use Konventix\Registration\Listeners\PromoteOnQuotaReleased;
use Konventix\Registration\Listeners\RecordPaymentOnOrderLineSettled;
use Konventix\Registration\Listeners\RecordPaymentOnOrderPaid;
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

        // D-REG-12: a freed quota unit promotes the oldest queued waitlist
        // entry — including units released by expired offers, which is what
        // makes the queue drain itself.
        Event::listen(QuotaReleased::class, PromoteOnQuotaReleased::class);

        // ADR-0036: billing party freezes at first invoice.
        Event::listen(InvoiceIssued::class, MarkInvoicedOnInvoiceIssued::class);

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
                    ->orderBy('name')
                    ->get()
                    ->map(fn (RegistrationType $type): array => PublicOfferData::ofType($type)->toArray())
                    ->all(),
            ],
            order: 10,
        );
    }
}

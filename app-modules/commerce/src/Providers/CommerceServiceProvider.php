<?php

declare(strict_types=1);

namespace Konventix\Commerce\Providers;

use Illuminate\Support\Facades\Event;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Listeners\CreateOrderOnSettlementRequested;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Registration\Events\RegistrationSettlementRequested;

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
        $this->app->make(NavigationRegistry::class)->add(
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
    }
}

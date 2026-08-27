<?php

declare(strict_types=1);

namespace Konventix\Commerce\Listeners;

use Konventix\Commerce\Actions\PlaceOrderFromSettlementRequest;
use Konventix\Commerce\Actions\PlaceRegistrationLineInCart;
use Konventix\Platform\Models\Brand;
use Konventix\Registration\Events\RegistrationSettlementRequested;
use Laravel\Pennant\Feature;

/**
 * The trigger that wakes Commerce from dormancy (SPEC.md "Activation",
 * ADR-0026): a registration type declared a settlement that moves money.
 * Registered in `CommerceServiceProvider::registerListeners()`.
 *
 * `Konventix\Registration\Events\RegistrationSettlementRequested` is the
 * one Registration class this module imports, allowed because events are
 * the public contract between modules (ADR-0004) —
 * `tests/RegistrationImportBoundaryTest.php` exempts exactly the `Events\`
 * sub-namespace, mirroring Admission's boundary test. Everything past this
 * line sees only the scalar payload.
 *
 * ADR-0047 point 3: an `instant` settlement request joins the registrant's
 * cart as a line instead of placing an order, once the brand's `commerce:
 * shop` feature is on — the registration still exists and still holds its
 * quota, only where the money settles changes. `invoice` settlements, and
 * every settlement on a brand with the feature off, keep the direct order
 * this listener always produced. `Platform`'s `Brand` is the allowed
 * cross-module primitive (`ArchitectureTest`'s allow-list) used only to
 * resolve the feature flag — never for anything else.
 *
 * Core module (never gated) — no `ShortCircuitsInactiveModule`.
 */
final class CreateOrderOnSettlementRequested
{
    private const string SETTLEMENT_INSTANT = 'instant';

    private const string SHOP_FEATURE = 'commerce:shop';

    public function __construct(
        private readonly PlaceOrderFromSettlementRequest $placeOrder,
        private readonly PlaceRegistrationLineInCart $placeInCart,
    ) {}

    public function handle(RegistrationSettlementRequested $event): void
    {
        $brand = Brand::query()->find($event->brandId);

        if ($event->settlement === self::SETTLEMENT_INSTANT && $brand !== null && Feature::for($brand)->active(self::SHOP_FEATURE)) {
            $this->placeInCart->handle(
                brandId: $event->brandId,
                registrantUserId: $event->registrantUserId,
                registrationId: $event->registrationId,
                requestKey: $event->requestKey,
                lines: $event->lines,
            );

            return;
        }

        $this->placeOrder->handle(
            registrationId: $event->registrationId,
            brandId: $event->brandId,
            settlement: $event->settlement,
            billingParty: $event->billingParty,
            lines: $event->lines,
            priceDisplay: $event->priceDisplay,
            termsDays: $event->termsDays,
            requestKey: $event->requestKey,
            payerUserId: $event->registrantUserId,
        );
    }
}

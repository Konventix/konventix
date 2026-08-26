<?php

declare(strict_types=1);

namespace Konventix\Commerce\Listeners;

use Konventix\Commerce\Actions\PlaceOrderFromSettlementRequest;
use Konventix\Registration\Events\RegistrationSettlementRequested;

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
 * Core module (never gated) — no `ShortCircuitsInactiveModule`.
 */
final class CreateOrderOnSettlementRequested
{
    public function __construct(
        private readonly PlaceOrderFromSettlementRequest $action,
    ) {}

    public function handle(RegistrationSettlementRequested $event): void
    {
        $this->action->handle(
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

<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * Any payment was recorded — a webhook-confirmed PSP payment or a manually
 * booked bank transfer (SPEC.md "Public contract" table). Distinct from
 * `OrderPaid`, which says the *order* is fully settled: this event is about
 * the money that arrived, that one is about what it means for the order.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final class PaymentReceived
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $orderId,
        public readonly string $brandId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $settlementMethod,
        public readonly ?string $externalReference,
        public readonly string $receivedAt,
    ) {}
}

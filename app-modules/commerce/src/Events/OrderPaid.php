<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * Emitted from the verified webhook path (`RecordPaymentSuccess`) and from
 * manual booking; consumed by Registration's `RecordPaymentOnOrderPaid`.
 * `settlementMethod` is the provider (`stripe|mollie|unzer|manual`),
 * `settlementAccountId` the account that took the money (null for manual).
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final class OrderPaid
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $brandId,
        public readonly string $settlesType,
        public readonly string $settlesId,
        public readonly string $amountPaid,
        public readonly string $currency,
        public readonly string $paidAt,
        public readonly string $settlementMethod,
        public readonly ?string $settlementAccountId = null,
    ) {}
}

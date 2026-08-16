<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * An adapter reported a failed or expired payment attempt (SPEC.md "Public
 * contract" table). The order stays pending — a failed attempt settles
 * nothing and a new checkout may follow.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final class PaymentFailed
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $orderId,
        public readonly string $brandId,
        public readonly string $settlementMethod,
        public readonly string $reasonCode,
        public readonly string $failedAt,
    ) {}
}

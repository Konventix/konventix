<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * An order is fully settled, by any adapter or by manual booking — the event
 * Registration consumes (ADR-0006, SPEC.md "Public contract" table).
 * Emitted by `Konventix\Commerce\Settlement\ManualSettlementAdapter`.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 * Registration does not consume this yet (TODO.md Phase 2 backlog:
 * `RegistrationSettlementRequested`/`OrderPaid` are not wired together in
 * Phase 1), but the event is the module's outbound contract regardless of
 * who is listening.
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
    ) {}
}

<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * An order is cancelled before or after settlement. Commerce's SPEC has
 * proposed this event since ADR-0006; ADR-0057 point 4 gives it its first
 * producers: the operator cancelling an order by hand, and the
 * `commerce:cancel-unpaid-reversals` sweep that cancels an order whose
 * reversed payment went unpaid past `repay_due_on`, with `reasonCode`
 * `reversal_unpaid`.
 *
 * Registration consumes this event (ADR-0057 point 5): on
 * `reasonCode: 'reversal_unpaid'` it cancels the registration through
 * `CancelRegistration` (reason code `payment_reversed`), releasing the
 * quota and emitting `RegistrationCancelled` — the SPEC's proposed
 * "`OrderCancelled` → decide whether the contractual act is cancelled too"
 * row, answered for that reason.
 *
 * `cancelledByUserId` is null when the sweep cancels rather than an
 * operator. `creditNoteId` is null where the order was never invoiced
 * (ADR-0031) — no document exists to correct.
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class OrderCancelled
{
    public function __construct(
        public string $orderId,
        public string $brandId,
        public string $settlesType,
        public string $settlesId,
        public ?string $cancelledByUserId,
        public string $reasonCode,
        public ?string $creditNoteId,
        public string $cancelledAt,
    ) {}
}

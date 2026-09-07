<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * Money went back to the payer (ADR-0037). Emitted by
 * `Konventix\Commerce\Actions\RefundOrder`.
 *
 * Distinct from `CreditNoteIssued`: the credit note is the *document*, this is
 * the *money*. A transfer (ADR-0036) issues the first without the second, so a
 * consumer counting refunds must listen here and not to the document.
 *
 * `settlesType`/`settlesId` are carried so Registration can react without a
 * lookup, exactly as `OrderPaid` does.
 *
 * `whole` is true when the refund covers the order's full gross total.
 * `amountRefunded` alone cannot say that — a partial refund and a full one
 * both carry an amount — and ADR-0057 point 5 needs the distinction:
 * Registration cancels the registration only on a whole-order refund,
 * exactly as it does on `OrderCancelled` with reason `reversal_unpaid`.
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class OrderRefunded
{
    public function __construct(
        public string $orderId,
        public string $brandId,
        public string $settlesType,
        public string $settlesId,
        public string $amountRefunded,
        public string $currency,
        public ?string $creditNoteId,
        public string $reasonCode,
        public string $refundedAt,
        public bool $whole,
    ) {}
}

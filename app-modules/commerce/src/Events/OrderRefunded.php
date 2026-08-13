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
    ) {}
}

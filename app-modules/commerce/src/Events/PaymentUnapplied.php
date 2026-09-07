<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * Money arrived for an order that is no longer waiting for it (ADR-0057
 * point 9, #6): a PSP success landing after `ExpireCarts`' checkout-grace
 * sweep cancelled the order. The payment is recorded `succeeded` — the
 * money really moved — and this event says the order it was meant for
 * cannot take it.
 *
 * **Module-internal.** Nothing outside Commerce may act on it: what
 * happens next is an operator decision the ADR does not automate. The
 * operator may prefer to reinstate the registration while the quota
 * allows, or to refund through the payment's own account —
 * `RefundOrder`'s provider leg without a credit note, because a cancelled
 * cart order has no document to correct.
 *
 * The data definition of "unapplied" is `Payment.status = succeeded AND
 * order.status = cancelled`, which `Payment::scopeUnapplied()` queries: this
 * event announces the moment, the scope finds every case including those
 * that predate it.
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class PaymentUnapplied
{
    public function __construct(
        public string $paymentId,
        public string $orderId,
        public string $brandId,
        public string $amount,
        public string $currency,
        public string $provider,
        public ?string $settlementAccountId,
        public string $receivedAt,
    ) {}
}

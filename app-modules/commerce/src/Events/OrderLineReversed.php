<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * One order line's settlement was undone by a payment reversal — the
 * mirror of `OrderLineSettled`, emitted per registration-kind line
 * alongside `PaymentReversed`, for a cart order only (ADR-0057 point 2).
 * Emitted by `Konventix\Commerce\Actions\RecordPaymentReversal`.
 *
 * A direct registration order has exactly one thing to unsettle and
 * `PaymentReversed` already says which, exactly as `OrderLineSettled`
 * exists only because a cart order settles more than one thing at once —
 * only such an order needs a per-line reversal event too. Registration's
 * per-line listener has its mirror here.
 *
 * `orderRequestKey` is the order's own `request_key` (ADR-0036), the same
 * value `OrderLineSettled` carries for the line it once settled.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final readonly class OrderLineReversed
{
    public function __construct(
        public string $orderId,
        public string $brandId,
        public string $kind,
        public string $refId,
        public string $orderRequestKey,
        public string $amountGross,
        public string $currency,
        public string $reversedAt,
        public ?string $settlementAccountId,
    ) {}
}

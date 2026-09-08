<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * A `payment_reversals` row was created or moved (ADR-0057 point 1).
 * **Module-internal**: unlike `PaymentReversed`, this fires for every
 * reversal on every transition — an opened dispute, a won one, a partial
 * return no other module may act on — because the operator has to see all
 * of them (ADR-0057 point 8: the notice kinds `payment-reversed` and
 * `dispute-opened`, and the console's "open reversals" list).
 *
 * Nothing outside Commerce listens to it. A consumer in another module
 * would be reacting to a state the ADR deliberately keeps inside: an open
 * dispute means the money may still come back, and a partial reversal has
 * no order-level meaning until #35's partial payments exist.
 * `PaymentReversed` is the cross-domain contract; this is the notice.
 *
 * `status` is the status the row now has, so a listener can tell the
 * opening of a dispute from its loss without re-reading the row.
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class PaymentReversalRecorded
{
    public function __construct(
        public string $reversalId,
        public string $paymentId,
        public string $orderId,
        public string $brandId,
        public string $kind,
        public string $status,
        public string $amount,
        public string $currency,
        public string $provider,
        public string $providerReference,
        public ?string $reasonCode,
        public string $occurredAt,
    ) {}
}

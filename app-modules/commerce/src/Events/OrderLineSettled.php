<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * One order line was paid (ADR-0047, Task 11): emitted per `OrderLine` from
 * `RecordPaymentSuccess::settleOrder()`, alongside `OrderPaid`, **only for
 * an order whose `settles_type` is `cart`**.
 *
 * A cart checkout produces one order settling a mix of products and
 * registrations (`settles_kind`/`settles_ref` per line); `OrderPaid` alone
 * only says "this order is paid", not which of its lines a listener should
 * act on. A direct registration order (`settles_type: 'registration'`,
 * still produced by `PlaceOrderFromSettlementRequest` for `invoice`
 * settlements and for `instant` ones on a brand with the shop feature off)
 * has exactly one thing to confirm, and `OrderPaid` already says which —
 * emitting this event for it too would let Registration's
 * `RecordPaymentOnOrderLineSettled` and `RecordPaymentOnOrderPaid` both
 * confirm the same registration from the same payment. So this event is
 * cart-orders-only by construction, not by a flag either listener has to
 * check: `RecordPaymentOnOrderPaid` handles every non-cart order exactly as
 * it always has, and this event is the *only* thing that reaches
 * Registration for a cart order's registration lines.
 *
 * `orderRequestKey` is the *order's own* `request_key` (`Order.request_key`,
 * ADR-0036) — the checked-out cart's own id, the same value on every line
 * of this order. **Not** the same concept `CartLineExpired`/
 * `CartLineRemoved`'s own `requestKey` carries: those name the *cart
 * line's* `request_key`, the settlement request that put a registration
 * line in the cart in the first place — a value this event has no access
 * to once checkout has turned the line into a plain `OrderLine` (which
 * carries no `request_key` column of its own). Nothing consumes
 * `orderRequestKey` today; it rides along for whatever wants to group a
 * cart order's own lines together, the way `Order.request_key` already
 * does at the order level.
 *
 * `kind`/`refId` are the line's `settles_kind`/`settles_ref` (`product` or
 * `registration`); a listener that only cares about one kind ignores the
 * other, the same discipline `CartLineExpired`'s consumers already apply.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final readonly class OrderLineSettled
{
    public function __construct(
        public string $orderId,
        public string $brandId,
        public string $kind,
        public string $refId,
        public string $orderRequestKey,
        public string $amountGross,
        public string $currency,
        public string $paidAt,
        public ?string $settlementAccountId,
    ) {}
}

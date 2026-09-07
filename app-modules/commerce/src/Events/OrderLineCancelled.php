<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * One order line's sale was undone by the order's cancellation — the mirror
 * of `OrderLineReversed`, emitted per registration-kind line alongside
 * `OrderCancelled`, for a cart order only (ADR-0057 points 4 and 5). The
 * `commerce:cancel-unpaid-reversals` sweep of point 4 emits it from
 * `Konventix\Commerce\Actions\CancelOrder`, with the same `reasonCode`
 * `OrderCancelled` carries.
 *
 * A direct registration order has exactly one thing to cancel and
 * `OrderCancelled` already says which, exactly as `OrderLineSettled` and
 * `OrderLineReversed` exist only because a cart order settles — and unsettles
 * — more than one thing at once. Without this event a cancelled cart order
 * would leave its registration lines confirmed and their quota held forever:
 * `OrderCancelled` alone cannot say which of its lines to cancel, and
 * Registration may not read the lines (ADR-0004).
 *
 * `orderRequestKey` is the order's own `request_key` (ADR-0036), the same
 * value `OrderLineSettled` and `OrderLineReversed` carry for the line.
 * `cancelledByUserId` is null when the sweep cancels rather than an operator,
 * exactly as on `OrderCancelled`.
 *
 * `kind`/`refId` are the line's `settles_kind`/`settles_ref` (`product` or
 * `registration`); a listener that only cares about one kind ignores the
 * other. Registration consumes this for a `registration` line whose order was
 * cancelled with reason `reversal_unpaid`, cancelling the registration
 * through `CancelRegistration` exactly as it does for a direct order.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final readonly class OrderLineCancelled
{
    public function __construct(
        public string $orderId,
        public string $brandId,
        public string $kind,
        public string $refId,
        public string $orderRequestKey,
        public string $reasonCode,
        public ?string $cancelledByUserId,
        public string $cancelledAt,
    ) {}
}

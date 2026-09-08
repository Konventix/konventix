<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Commerce\Events\OrderLineCancelled;
use Konventix\Registration\Actions\CancelRegistrationForUndoneOrder;

/**
 * The per-line counterpart of `CancelRegistrationOnOrderUndone` (ADR-0057
 * points 4 and 5) — the same relationship `RecordPaymentOnOrderLineSettled`
 * has to `RecordPaymentOnOrderPaid`, and `RecordReversalOnOrderLineReversed`
 * to `RecordReversalOnPaymentReversed`.
 *
 * `OrderLineCancelled` is emitted only for a `settles_type: 'cart'` order
 * (its own docblock says why), so the two cancellation listeners partition on
 * which event Commerce chose to emit, never on a flag either one checks.
 * Without this listener the unpaid-reversal sweep of ADR-0057 point 4 would
 * cancel a cart order and leave its registrations confirmed with their quota
 * held forever — `OrderCancelled` alone cannot say which lines it undid, and
 * Registration may not read the lines (ADR-0004).
 *
 * `Konventix\Commerce\Events\OrderLineCancelled` is the one Commerce class
 * this listener imports, allowed because events are the public contract
 * between modules (ADR-0004). A `product`-kind line is Commerce's own
 * business and is ignored here, and so is any reason code this module has
 * not been told to act on — exactly as at order level, and mapped through
 * the same `match` so the two cannot drift.
 *
 * Two reasons reach it. `reversal_unpaid` is the sweep of ADR-0057 point 4.
 * `order_refunded` is the whole-order refund of ADR-0037 (#73,
 * whole-branch review finding 3): `CancelRegistrationOnOrderUndone` sees a
 * cart order's `OrderRefunded` and cannot act on it — its `settlesId` is
 * the cart's — so before this arm the money went back while the place, the
 * quota and the admission stayed standing, and the same button on a direct
 * order released them.
 *
 * Core module (never gated) — no `ShortCircuitsInactiveModule`, same
 * reasoning as `RecordPaymentOnOrderLineSettled`.
 */
final class CancelRegistrationOnOrderLineCancelled
{
    private const string KIND_REGISTRATION = 'registration';

    /**
     * The order-level reason that means "the reversed payment was never
     * repaid" (ADR-0057 point 4). The line carries the order's own.
     */
    private const string REASON_REVERSAL_UNPAID = 'reversal_unpaid';

    /**
     * The order-level reason that means "the whole order was refunded"
     * (ADR-0037, #73) — `Commerce\Actions\RecordRefund::REASON_ORDER_REFUNDED`,
     * restated here rather than imported: a reason code is a scalar on the
     * event's contract, not a class Registration may reach for (ADR-0004).
     */
    private const string REASON_ORDER_REFUNDED = 'order_refunded';

    public function __construct(
        private readonly CancelRegistrationForUndoneOrder $action,
    ) {}

    public function handle(OrderLineCancelled $event): void
    {
        if ($event->kind !== self::KIND_REGISTRATION) {
            return;
        }

        $reasonCode = match ($event->reasonCode) {
            self::REASON_REVERSAL_UNPAID => CancelRegistrationForUndoneOrder::REASON_PAYMENT_REVERSED,
            self::REASON_ORDER_REFUNDED => CancelRegistrationForUndoneOrder::REASON_ORDER_REFUNDED,
            default => null,
        };

        if ($reasonCode === null) {
            return;
        }

        $this->action->handle(
            brandId: $event->brandId,
            registrationId: $event->refId,
            reasonCode: $reasonCode,
            cancelledByUserId: $event->cancelledByUserId,
        );
    }
}

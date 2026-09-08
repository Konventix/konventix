<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions\Concerns;

use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;

/**
 * **One lock order for the whole money path: the order row first, then the
 * payment** (review NEW-5).
 *
 * Every write that undoes money touches two or three rows — the order, the
 * payment, and the `payment_reversals` row whose unique key both sides of a
 * refund converge on — and two paths reach them at the same time: the
 * console's `RefundOrder` and the provider's own notification through
 * `RecordPaymentReversal`. Taking them in opposite orders is a deadlock:
 * the console held the order and waited on the reversal row's unique index
 * while the webhook held the payment and its uncommitted row and waited on
 * the order. Postgres raises SQLSTATE 40P01 and kills one of them — and it
 * is not a `UniqueConstraintViolationException`, so the convergence catch in
 * `RecordOwnRefund` does not see it: the operator gets a 500 for a refund
 * whose money did leave.
 *
 * So: **order, then payment, then the reversal row.** `RecordRefund`,
 * `RecordOwnRefund`, `RecordPaymentReversal` and `RefundOrder`'s unapplied
 * path all cite this docblock and all follow it. A path that needs only one
 * of the rows still takes it in this position — re-locking a row the same
 * transaction already holds costs nothing, and skipping ahead is what makes
 * the cycle.
 *
 * The reversal row is deliberately last: it is the row a *second* writer
 * blocks on through the unique index rather than through a lock it can see,
 * so nothing may be held after it that another path takes before it.
 */
trait LocksMoneyRowsInOneOrder
{
    /**
     * The order row, locked. Null where the order is gone — a caller that
     * needs it says so itself.
     */
    private function lockOrder(string $orderId): ?Order
    {
        return Order::withoutBrandScope()->whereKey($orderId)->lockForUpdate()->first();
    }

    /**
     * The payment row, locked. Called only after `lockOrder()`, per the
     * class docblock.
     */
    private function lockPayment(string $paymentId): ?Payment
    {
        return Payment::withoutBrandScope()->whereKey($paymentId)->lockForUpdate()->first();
    }
}

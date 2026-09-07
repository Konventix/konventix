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
 * business and is ignored here; so is any reason code other than
 * `reversal_unpaid`, exactly as at order level.
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

    public function __construct(
        private readonly CancelRegistrationForUndoneOrder $action,
    ) {}

    public function handle(OrderLineCancelled $event): void
    {
        if ($event->kind !== self::KIND_REGISTRATION) {
            return;
        }

        if ($event->reasonCode !== self::REASON_REVERSAL_UNPAID) {
            return;
        }

        $this->action->handle(
            brandId: $event->brandId,
            registrationId: $event->refId,
            reasonCode: CancelRegistrationForUndoneOrder::REASON_PAYMENT_REVERSED,
            cancelledByUserId: $event->cancelledByUserId,
        );
    }
}

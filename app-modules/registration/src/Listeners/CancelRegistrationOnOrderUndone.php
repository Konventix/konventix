<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Commerce\Events\OrderCancelled;
use Konventix\Commerce\Events\OrderRefunded;
use Konventix\Registration\Actions\CancelRegistrationForUndoneOrder;

/**
 * ADR-0057 point 5, the answer to the SPEC's long-proposed "`OrderCancelled`
 * → decide whether the contractual act is cancelled too" row: yes, when the
 * order no longer stands at all.
 *
 * Two events say that at order level, and both are handled here because the
 * reaction is identical — `CancelRegistrationForUndoneOrder`, which releases
 * the quota and emits `RegistrationCancelled`:
 *
 * - `OrderCancelled` with reason `reversal_unpaid`: the repayment deadline of
 *   ADR-0057 point 4 passed and the `commerce:cancel-unpaid-reversals` sweep
 *   cancelled the order.
 * - `OrderCancelled` with reason `payment_window_expired`: nobody paid the
 *   direct `instant` order inside the carts' own payment window and
 *   `commerce:expire-registration-orders` called it off (#131). Without this
 *   arm the sweep would move an order's status and release nothing — a
 *   registration on a brand with no shop feature held its quota for good.
 *   The two reasons stay distinct all the way down to the audit column:
 *   `CancelRegistrationForUndoneOrder` maps each to its own reason code.
 *
 * Any other reason code is an order-level fact this module has not been told
 * to act on, and is ignored.
 * - `OrderRefunded` with `whole: true`: the whole-order refund of ADR-0037,
 *   whose downstream half was left open there. `amountRefunded` alone cannot
 *   say whether a refund was whole, which is why the event carries the flag;
 *   a partial refund leaves the contractual act standing.
 *
 * Only a *direct* registration order reaches here. A cart order's own
 * `OrderCancelled` cannot say which of its lines to cancel — its `settlesId`
 * is the cart's — so its registration lines arrive as `OrderLineCancelled`
 * and `CancelRegistrationOnOrderLineCancelled` handles them, exactly the
 * partition `OrderPaid`/`OrderLineSettled` already draws. The guard on
 * `settlesType` therefore needs no dedicated cart branch.
 *
 * The reversal itself never reaches here — `PaymentReversed` only revives the
 * receivable (`RecordReversalOnPaymentReversed`); the seat is kept until the
 * deadline. Registration never reads a reversal row (ADR-0004).
 *
 * `Konventix\Commerce\Events\OrderCancelled`/`OrderRefunded` are the Commerce
 * classes this listener imports, allowed because events are the public
 * contract between modules (ADR-0004). Registered twice in
 * `RegistrationServiceProvider::registerListeners()`, once per event, both
 * bound to this same method — the shape
 * `CancelPendingRegistrationOnCartLineGone` established.
 *
 * Core module (never gated) — no `ShortCircuitsInactiveModule`, same
 * reasoning as `RecordPaymentOnOrderPaid`.
 */
final class CancelRegistrationOnOrderUndone
{
    private const string SETTLES_REGISTRATION = 'registration';

    /**
     * The order-level reason that means "the reversed payment was never
     * repaid" (ADR-0057 point 4).
     */
    private const string REASON_REVERSAL_UNPAID = 'reversal_unpaid';

    /**
     * The order-level reason that means "nobody paid inside the payment
     * window" (#131) — Commerce's `commerce:expire-registration-orders`
     * sweep, the direct-order counterpart of the cart expiry that already
     * reaches this module as `CartLineExpired`.
     */
    private const string REASON_PAYMENT_WINDOW_EXPIRED = 'payment_window_expired';

    public function __construct(
        private readonly CancelRegistrationForUndoneOrder $action,
    ) {}

    public function handle(OrderCancelled|OrderRefunded $event): void
    {
        if ($event->settlesType !== self::SETTLES_REGISTRATION) {
            return;
        }

        $reasonCode = $this->registrationReasonCode($event);

        if ($reasonCode === null) {
            return;
        }

        $this->action->handle(
            brandId: $event->brandId,
            registrationId: $event->settlesId,
            reasonCode: $reasonCode,
            // Null when the sweep cancelled rather than an operator, and
            // always null for a refund — ADR-0057 point 4's actor-less case.
            cancelledByUserId: $event instanceof OrderCancelled ? $event->cancelledByUserId : null,
        );
    }

    /**
     * The reason code to cancel the registration with, or null when this
     * event does not undo the sale at all.
     *
     * Both arms name their event class rather than leaning on `default`, so
     * neither one silently absorbs a third event class if this union ever
     * grows. There is deliberately no `default` arm: phpstan proves the union
     * exhausted by the two above (it rejects a `default` here as unreachable),
     * and a member added to the union without an arm becomes a build error
     * instead of a silent "ignore".
     */
    private function registrationReasonCode(OrderCancelled|OrderRefunded $event): ?string
    {
        return match (true) {
            $event instanceof OrderCancelled => match ($event->reasonCode) {
                self::REASON_REVERSAL_UNPAID => CancelRegistrationForUndoneOrder::REASON_PAYMENT_REVERSED,
                self::REASON_PAYMENT_WINDOW_EXPIRED => CancelRegistrationForUndoneOrder::REASON_PAYMENT_WINDOW_EXPIRED,
                default => null,
            },
            $event instanceof OrderRefunded => $event->whole
                ? CancelRegistrationForUndoneOrder::REASON_ORDER_REFUNDED
                : null,
        };
    }
}

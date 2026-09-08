<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\OrderLineCancelled;
use Konventix\Commerce\Events\OrderRefunded;
use Konventix\Commerce\Jobs\RelayRefundEvents;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;

/**
 * The **domain leg** of a refund (ADR-0037): the credit note where an
 * invoice exists, `OrderStatus::Refunded`, and `OrderRefunded`. No
 * provider call and no guards — deliberately.
 *
 * Two callers, and the split is exactly the difference between them.
 * `RefundOrder` is the operator's refund: it keeps ADR-0037's check-in
 * guard and the paid guard, moves the money through the provider, and then
 * delegates here. `RecordPaymentReversal` handles the refund the operator
 * made in the provider's own dashboard instead (ADR-0057 point 1,
 * `ReversalKind::ExternalRefund`): the money has already moved, so there is
 * nothing to call and nothing left to guard — the check-in guard cannot
 * run after the fact, which is why that path logs a warning saying so.
 *
 * A **whole** refund of a *cart* order also emits `OrderLineCancelled` per
 * registration-kind line (whole-branch review finding 3). Without it the
 * money went back and the place, the quota and the admission stayed
 * standing: `CancelRegistrationOnOrderUndone` reacts to `OrderRefunded` only
 * for `settlesType: 'registration'`, because a cart order's `settlesId` is
 * the cart's and cannot say which of its lines to release. That is the same
 * partition `OrderPaid`/`OrderLineSettled` draws, and the same per-line
 * event `CancelOrderForUnpaidReversal` emits for the same reason — so the
 * console's refund button now behaves the same way whichever kind of order
 * paid for the seat. A *partial* refund releases nothing, exactly as
 * `OrderRefunded.whole` already told Registration for a direct order.
 *
 * Escapes BrandScope explicitly (ADR-0010): the reversal caller runs on the
 * stateless api surface, where no brand is resolved from the host.
 *
 * **The events leave after the commit, never inside it (#236).** The money
 * has already moved by the time this Action runs — `RefundOrder` calls the
 * provider first — so the credit note, the `refunded` status and the
 * provider's own act have to survive whatever a consumer does with them. On
 * dev they did not: `CancelRegistrationOnOrderLineCancelled` threw inside the
 * console's refund request, the rollback took the credit note and the status
 * with it, and the order sat at `paid` with 11.90 EUR already back on the
 * card.
 *
 * The guarantee is made here rather than in each caller, because it has to
 * hold for **every** caller (fix round 1, review finding 1): dispatching
 * merely after this Action's own `DB::transaction()` still ran the consumers
 * inside `RecordPaymentReversal`'s outer transaction, so the webhook path —
 * the one where no brand is ever resolved — kept the old defect. The events
 * go to `RelayRefundEvents` with `->afterCommit()`, which registers on the
 * same `db.transactions` manager `DB::afterCommit()` uses: it fires when the
 * **outermost** applicable transaction commits, and immediately when there is
 * none. That the relay is a queued job is the other half — see its docblock,
 * and `SPEC.md`'s refund section for the window that remains.
 */
final class RecordRefund
{
    /**
     * The order-level reason the per-line cancellation carries — the refund's
     * counterpart of `CancelOrderForUnpaidReversal::REASON_REVERSAL_UNPAID`,
     * and the value `CancelRegistrationOnOrderLineCancelled` keys on.
     * Deliberately not the operator's `RefundReason`: that says *why* the
     * money went back, this says *what happened to the order*.
     */
    public const string REASON_ORDER_REFUNDED = 'order_refunded';

    private const string SETTLES_CART = 'cart';

    private const string KIND_REGISTRATION = 'registration';

    public function __construct(
        private readonly IssueCreditNote $issueCreditNote,
        private readonly RecordOwnRefund $recordOwnRefund,
    ) {}

    /**
     * `$payment` and `$providerReference` come from `RefundOrder` alone
     * (#247): the payment the money went back through and the identifier the
     * provider gave the refund. The reversal path passes neither — its
     * `payment_reversals` row already exists, written by
     * `RecordPaymentReversal` from the provider's own notification.
     */
    public function handle(
        Order $order,
        string $reasonCode,
        ?string $reasonNote = null,
        ?string $amount = null,
        ?Payment $payment = null,
        ?string $providerReference = null,
    ): Order {
        return DB::transaction(function () use ($order, $reasonCode, $reasonNote, $amount, $payment, $providerReference): Order {
            // An order paid at checkout was never invoiced, so there is no
            // document to correct (ADR-0031) — the money still goes back, it
            // simply produces no paperwork.
            $creditNote = Invoice::withoutBrandScope()
                ->where('brand_id', $order->brand_id)
                ->where('order_id', $order->id)
                ->exists()
                    ? $this->issueCreditNote->handle(
                        order: $order,
                        reasonCode: IssueCreditNote::REASON_REFUND,
                        reasonNote: $reasonNote,
                        totalGross: $amount,
                    )
                    : null;

            $order->forceFill(['status' => OrderStatus::Refunded])->save();

            $this->recordProviderReference($order, $payment, $providerReference, $amount);

            $totalGross = $order->total_gross;

            $whole = $amount === null
                || (is_numeric($amount) && bccomp($amount, $totalGross, 2) === 0);

            $refundedAt = now()->toIso8601String();

            /** @var list<object> $events */
            $events = [];

            $events[] = new OrderRefunded(
                orderId: $order->id,
                brandId: $order->brand_id,
                settlesType: $order->settles_type,
                settlesId: $order->settles_id,
                amountRefunded: $amount ?? $totalGross,
                currency: $order->currency,
                creditNoteId: $creditNote?->id,
                reasonCode: $reasonCode,
                refundedAt: $refundedAt,
                // ADR-0057 point 5: Registration cannot tell a full refund
                // from a partial one from the amount alone. `$amount` is
                // guarded numeric because a caller may pass any string;
                // bccomp itself requires numeric-string.
                whole: $whole,
            );

            if ($whole) {
                $events = [...$events, ...$this->orderLineCancelledEvents($order, $refundedAt)];
            }

            // Queued from inside the transaction but pushed only once the
            // outermost one commits (#236): `->afterCommit()` hands the push
            // to `db.transactions`, which also drops it if that transaction
            // rolls back — so an announcement can never outlive a refund that
            // was not recorded, and a consumer can never unrecord one.
            RelayRefundEvents::dispatch($order->id, $events)->afterCommit();

            return $order->refresh();
        });
    }

    /**
     * The provider's own identifier for the refund, on a row of its own
     * (#247) — written inside this transaction, beside the credit note and
     * the status, by `RecordOwnRefund`.
     *
     * Skipped where there is nothing to record: a `manual` refund the
     * organizer wires back themselves has no reference, and an order booked
     * by hand has no payment.
     */
    private function recordProviderReference(
        Order $order,
        ?Payment $payment,
        ?string $providerReference,
        ?string $amount,
    ): void {
        if ($payment === null || $providerReference === null || $providerReference === '') {
            return;
        }

        $this->recordOwnRefund->handle(
            order: $order,
            payment: $payment,
            amount: $amount ?? (string) $order->total_gross,
            providerReference: $providerReference,
        );
    }

    /**
     * The registration lines a wholly refunded cart order gives back, one
     * event each, built but not dispatched — the caller sends them after the
     * commit (#236). `CancelOrderForUnpaidReversal::emitOrderLineCancelled()`'s
     * twin, down to the `position` ordering and the registration-kind filter.
     *
     * A direct registration order is skipped: `OrderRefunded` already names
     * the one thing it settles, and emitting both would cancel it twice.
     * `cancelledByUserId` is null because `OrderRefunded` carries no actor
     * either — the two paths must write the same audit row.
     *
     * @return list<OrderLineCancelled>
     */
    private function orderLineCancelledEvents(Order $order, string $refundedAt): array
    {
        if ($order->settles_type !== self::SETTLES_CART) {
            return [];
        }

        $lines = OrderLine::withoutBrandScope()
            ->where('order_id', $order->id)
            ->where('settles_kind', self::KIND_REGISTRATION)
            ->whereNotNull('settles_ref')
            ->orderBy('position')
            ->get();

        $events = [];

        foreach ($lines as $line) {
            if ($line->settles_kind === null || $line->settles_ref === null) {
                continue;
            }

            $events[] = new OrderLineCancelled(
                orderId: $order->id,
                brandId: $order->brand_id,
                kind: $line->settles_kind,
                refId: $line->settles_ref,
                orderRequestKey: $order->request_key,
                reasonCode: self::REASON_ORDER_REFUNDED,
                cancelledByUserId: null,
                cancelledAt: $refundedAt,
            );
        }

        return $events;
    }
}

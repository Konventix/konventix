<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Events\OrderLineSettled;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\PaymentReceived;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;

/**
 * The webhook-side settlement transition (ADR-0039), shared by every PSP
 * adapter: mark the payment succeeded, and mark its order paid unless a
 * previous attempt already did. Idempotent under a row lock — providers
 * retry notifications, and an offline payer's webhook may race the return
 * page's re-fetch; replays must converge on one `OrderPaid`, ever.
 *
 * All reads escape BrandScope explicitly (ADR-0010): this runs on the
 * stateless api surface, where no brand is resolved from the host.
 */
final class RecordPaymentSuccess
{
    public function handle(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment): Payment {
            $locked = Payment::withoutBrandScope()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === PaymentStatus::Succeeded) {
                return $locked;
            }

            $locked->forceFill([
                'status' => PaymentStatus::Succeeded,
                'received_at' => now(),
                'failure_reason' => null,
                'failed_at' => null,
            ])->save();

            event(new PaymentReceived(
                paymentId: $locked->id,
                orderId: $locked->order_id,
                brandId: $locked->brand_id,
                amount: $locked->amount,
                currency: $locked->currency,
                settlementMethod: $locked->provider,
                externalReference: $locked->external_id,
                receivedAt: $locked->received_at->toIso8601String(),
                settlementAccountId: $locked->settlement_account_id,
            ));

            $this->settleOrder($locked);

            return $locked->refresh();
        });
    }

    /**
     * A second successful attempt against an already-paid order records the
     * payment but must not re-emit `OrderPaid` — Registration confirmed on
     * the first one, and the duplicate money is an operator refund case,
     * not a second confirmation.
     */
    private function settleOrder(Payment $payment): void
    {
        $order = Order::withoutBrandScope()
            ->whereKey($payment->order_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($order->status !== OrderStatus::Pending) {
            return;
        }

        $order->forceFill([
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ])->save();

        event(new OrderPaid(
            orderId: $order->id,
            brandId: $order->brand_id,
            settlesType: $order->settles_type,
            settlesId: $order->settles_id,
            amountPaid: $payment->amount,
            currency: $payment->currency,
            paidAt: $order->paid_at->toIso8601String(),
            settlementMethod: $payment->provider,
            settlementAccountId: $payment->settlement_account_id,
        ));

        $this->emitOrderLineSettled($order, $payment);
    }

    /**
     * `OrderLineSettled` per line, **cart orders only** (`OrderLineSettled`'s
     * own docblock explains why): a `settles_type: 'registration'` order —
     * still produced directly for `invoice` settlements and for `instant`
     * ones on a brand with the shop feature off — has exactly one thing to
     * confirm, and `OrderPaid` above already says which. Only a cart
     * checkout's order settles more than one thing at once, so only it
     * needs a per-line event to say which of them were paid.
     */
    private function emitOrderLineSettled(Order $order, Payment $payment): void
    {
        if ($order->settles_type !== 'cart') {
            return;
        }

        $lines = OrderLine::withoutBrandScope()->where('order_id', $order->id)->orderBy('position')->get();

        foreach ($lines as $line) {
            if ($line->settles_kind === null || $line->settles_ref === null) {
                continue;
            }

            event(new OrderLineSettled(
                orderId: $order->id,
                brandId: $order->brand_id,
                kind: $line->settles_kind,
                refId: $line->settles_ref,
                requestKey: $order->request_key,
                amountGross: $line->line_gross,
                currency: $payment->currency,
                paidAt: $order->paid_at->toIso8601String(),
                settlementAccountId: $payment->settlement_account_id,
            ));
        }
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;

/**
 * The `payment_reversals` row for a refund **Konventix itself** made through
 * the provider (#247): `ReversalKind::Refund`, final on arrival, carrying
 * the reference the provider assigned the money going back.
 *
 * Written directly rather than through `RecordPaymentReversal`, and that is
 * the whole point of it being its own Action: `RecordPaymentReversal` exists
 * to *react* to money leaving — revive the receivable, or undo the sale —
 * and both callers here have already done everything that follows from this
 * refund. Running the reversal reactions on top would refund the refund.
 *
 * Two callers: `RecordRefund` (the ordinary refund of a paid order, inside
 * its own transaction beside the credit note and the status) and
 * `RefundOrder`'s unapplied path (ADR-0057 point 9, #6 — money that arrived
 * for an order nobody could apply it to, sent back with no document to
 * correct).
 *
 * Its second job is convergence. The provider announces our own refund back
 * to us — Stripe's `charge.refunded`, and the reconciliation sweep of #235 —
 * and both name this reference; the unique `(provider, provider_reference)`
 * key makes them find this row instead of recording an `external_refund`
 * against an order we refunded ourselves.
 *
 * Escapes BrandScope by taking `brand_id` from the order rather than from
 * ambient context (ADR-0010).
 */
final class RecordOwnRefund
{
    public function handle(
        Order $order,
        Payment $payment,
        string $amount,
        string $providerReference,
    ): PaymentReversal {
        $reversal = new PaymentReversal([
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'settlement_account_id' => $payment->settlement_account_id,
            'provider' => $payment->provider,
            'kind' => ReversalKind::Refund,
            'status' => ReversalStatus::Final,
            'amount' => $amount,
            'currency' => $order->currency,
            'provider_reference' => $providerReference,
            // The operator's reason lives on the credit note and the audit
            // entry; this row is about the money's identity at the provider.
            'reason_code' => null,
            // A refund costs no chargeback fee — that is what makes it the
            // cheaper answer to a disputed payment (ADR-0057 point 1).
            'fee_amount' => null,
            'occurred_at' => now(),
            'finalized_at' => now(),
        ]);

        $reversal->forceFill(['brand_id' => $order->brand_id])->save();

        return $reversal;
    }
}

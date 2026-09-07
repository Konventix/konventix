<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Events\OrderLineReversed;
use Konventix\Commerce\Events\PaymentReversalRecorded;
use Konventix\Commerce\Events\PaymentReversed;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use Konventix\Commerce\Settlement\RepaymentWindow;

/**
 * The webhook-side reversal transition (ADR-0057 point 7), shared by every
 * PSP adapter and the sibling of `RecordPaymentSuccess`: money that had
 * settled left again. Stripe maps its signed dispute and refund events onto
 * it; Mollie and Unzer their fetched chargeback lists.
 *
 * Idempotent by construction — the row is unique per `(provider,
 * provider_reference)`, the payment is row-locked, and only `Open` ever
 * moves. A replay with the status the row already has returns it untouched,
 * so a provider that notifies three times produces one `PaymentReversed`.
 *
 * **This Action never asks whether anyone checked in.** ADR-0037's guard
 * belongs to the *voluntary* refund, which a human starts; a chargeback has
 * already happened, and attendance does not undo it. Attendance decides
 * only whether the deadline sweep may later cancel the order (ADR-0057
 * point 4), which is where `CheckedInAdmission` is read.
 *
 * All reads escape BrandScope explicitly (ADR-0010): this runs on the
 * stateless api surface, where no brand is resolved from the host.
 */
final class RecordPaymentReversal
{
    public function __construct(
        private readonly RepaymentWindow $window,
        private readonly RecordRefund $recordRefund,
    ) {}

    public function handle(
        Payment $payment,
        ReversalKind $kind,
        ReversalStatus $status,
        string $amount,
        string $currency,
        string $providerReference,
        ?string $reasonCode,
        ?string $feeAmount,
        CarbonInterface $occurredAt,
    ): PaymentReversal {
        return DB::transaction(function () use (
            $payment,
            $kind,
            $status,
            $amount,
            $currency,
            $providerReference,
            $reasonCode,
            $feeAmount,
            $occurredAt,
        ): PaymentReversal {
            $locked = Payment::withoutBrandScope()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = PaymentReversal::withoutBrandScope()
                ->where('provider', $locked->provider)
                ->where('provider_reference', $providerReference)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->transition($existing, $locked, $status, $occurredAt);
            }

            $reversal = new PaymentReversal([
                'payment_id' => $locked->id,
                'order_id' => $locked->order_id,
                'settlement_account_id' => $locked->settlement_account_id,
                'provider' => $locked->provider,
                'kind' => $kind,
                'status' => $status,
                'amount' => $amount,
                'currency' => $currency,
                'provider_reference' => $providerReference,
                'reason_code' => $reasonCode,
                'fee_amount' => $feeAmount,
                'occurred_at' => $occurredAt,
                'finalized_at' => $status === ReversalStatus::Open ? null : $occurredAt,
            ]);
            // brand_id from the payment, not ambient context (ADR-0010):
            // the api surface resolves no brand.
            $reversal->forceFill(['brand_id' => $locked->brand_id])->save();

            $this->announce($reversal);
            $this->react($reversal, $locked);

            return $reversal->refresh();
        });
    }

    /**
     * `Open → Final` (the dispute was lost) and `Open → Withdrawn` (it was
     * won) are the only moves there are. A decided reversal is terminal:
     * providers do not guarantee notification order, and a stale `lost`
     * arriving after a `won` would otherwise reverse money that stayed.
     */
    private function transition(
        PaymentReversal $reversal,
        Payment $payment,
        ReversalStatus $status,
        CarbonInterface $occurredAt,
    ): PaymentReversal {
        if ($reversal->status === $status || $reversal->status !== ReversalStatus::Open) {
            return $reversal;
        }

        // `occurred_at` keeps saying when the dispute opened; `finalized_at`
        // says when it was decided, which is the date the deadline counts
        // from.
        $reversal->forceFill([
            'status' => $status,
            'finalized_at' => $occurredAt,
        ])->save();

        $this->announce($reversal);
        $this->react($reversal, $payment);

        return $reversal->refresh();
    }

    /**
     * The operator sees every reversal on every transition (ADR-0057
     * point 8) — an opened dispute and a won one included, neither of which
     * crosses the module boundary.
     */
    private function announce(PaymentReversal $reversal): void
    {
        event(new PaymentReversalRecorded(
            reversalId: $reversal->id,
            paymentId: $reversal->payment_id,
            orderId: $reversal->order_id,
            brandId: $reversal->brand_id,
            kind: $reversal->kind->value,
            status: $reversal->status->value,
            amount: (string) $reversal->amount,
            currency: $reversal->currency,
            provider: $reversal->provider,
            providerReference: $reversal->provider_reference,
            reasonCode: $reversal->reason_code,
            occurredAt: $reversal->occurred_at->toIso8601String(),
        ));
    }

    /**
     * Only a *final* reversal has a consequence, and which one depends on
     * the kind (ADR-0057 point 1): a return or a lost dispute revives the
     * receivable, an external refund undoes the sale.
     */
    private function react(PaymentReversal $reversal, Payment $payment): void
    {
        if ($reversal->status !== ReversalStatus::Final) {
            return;
        }

        if ($reversal->kind === ReversalKind::ExternalRefund) {
            $this->recordExternalRefund($reversal);

            return;
        }

        $this->reviveReceivable($reversal, $payment);
    }

    /**
     * ADR-0057 point 3: the sale stands, the receivable revives. No credit
     * note — the Rechnung documents a sale that happened and stays as it
     * was sealed (ADR-0031, ADR-0048); the receivable lives on the reversal
     * row, and the provider's fee is the organizer's cost on their own
     * statement (ADR-0007).
     */
    private function reviveReceivable(PaymentReversal $reversal, Payment $payment): void
    {
        if (! $this->isWholeAmount($reversal, $payment)) {
            // Mollie allows a partial chargeback. There is no amount the
            // order could be settled *for* today, so this is an operator
            // case until #35's partial payments exist: the row and the
            // notice carry it, and nothing else moves.
            Log::warning('Partial payment reversal recorded; order and payment left untouched (#35).', [
                'reversal_id' => $reversal->id,
                'payment_id' => $payment->id,
                'order_id' => $reversal->order_id,
                'reversed_amount' => (string) $reversal->amount,
                'payment_amount' => (string) $payment->amount,
            ]);

            return;
        }

        $payment->forceFill(['status' => PaymentStatus::Reversed])->save();

        $reversedAt = $reversal->finalized_at ?? $reversal->occurred_at;

        $reversal->forceFill([
            'repay_due_on' => $this->window->dueOnFrom($reversedAt, $reversal->brand_id),
        ])->save();

        $order = Order::withoutBrandScope()
            ->whereKey($reversal->order_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($order->status === OrderStatus::Paid) {
            // `paid_at` deliberately stays: it records that the money once
            // arrived, which is what the invoice says too.
            $order->forceFill(['status' => OrderStatus::PaymentReversed])->save();
        } else {
            // Already cancelled or refunded: there is no receivable to
            // revive, and forcing one would contradict a decision a human
            // or another Action already made. `PaymentReversed` still
            // fires — ADR-0057 point 2 emits it whenever a reversal becomes
            // final, and the money left whatever the order says.
            Log::warning('Payment reversal for an order that is not paid; order left as it is.', [
                'reversal_id' => $reversal->id,
                'order_id' => $order->id,
                'order_status' => $order->status->value,
            ]);
        }

        event(new PaymentReversed(
            reversalId: $reversal->id,
            paymentId: $payment->id,
            orderId: $order->id,
            brandId: $order->brand_id,
            settlesType: $order->settles_type,
            settlesId: $order->settles_id,
            kind: $reversal->kind->value,
            amount: (string) $reversal->amount,
            currency: $reversal->currency,
            settlementMethod: $payment->provider,
            settlementAccountId: $payment->settlement_account_id,
            providerReference: $reversal->provider_reference,
            reasonCode: $reversal->reason_code,
            repayDueOn: $reversal->repay_due_on?->toDateString(),
            reversedAt: $reversedAt->toIso8601String(),
        ));

        $this->emitOrderLineReversed($order, $reversal, $payment, $reversedAt);
    }

    /**
     * The inverse of `RecordPaymentSuccess::emitOrderLineSettled`, and for
     * the same reason: only a cart order settles more than one thing at
     * once, so only it needs a per-line event to say which of them were
     * unsettled. A `product`-kind line is Commerce's own business —
     * `OrderLineReversed` exists to give Registration's per-line listener
     * its mirror.
     */
    private function emitOrderLineReversed(
        Order $order,
        PaymentReversal $reversal,
        Payment $payment,
        CarbonInterface $reversedAt,
    ): void {
        if ($order->settles_type !== 'cart') {
            return;
        }

        $lines = OrderLine::withoutBrandScope()
            ->where('order_id', $order->id)
            ->where('settles_kind', 'registration')
            ->whereNotNull('settles_ref')
            ->orderBy('position')
            ->get();

        foreach ($lines as $line) {
            if ($line->settles_kind === null || $line->settles_ref === null) {
                continue;
            }

            event(new OrderLineReversed(
                orderId: $order->id,
                brandId: $order->brand_id,
                kind: $line->settles_kind,
                refId: $line->settles_ref,
                orderRequestKey: $order->request_key,
                amountGross: $line->line_gross,
                currency: $reversal->currency,
                reversedAt: $reversedAt->toIso8601String(),
                settlementAccountId: $payment->settlement_account_id,
            ));
        }
    }

    /**
     * ADR-0057 point 1: an `external_refund` is the operator's own decision
     * made in the wrong place. Its reaction is the refund's, not the
     * receivable's — everything `RefundOrder` does after the provider call,
     * because the provider call already happened.
     */
    private function recordExternalRefund(PaymentReversal $reversal): void
    {
        $order = Order::withoutBrandScope()
            ->whereKey($reversal->order_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($order->status !== OrderStatus::Paid) {
            Log::warning('External refund for an order that is not paid; order left as it is.', [
                'reversal_id' => $reversal->id,
                'order_id' => $order->id,
                'order_status' => $order->status->value,
            ]);

            return;
        }

        // ADR-0037's check-in guard cannot run after the fact: the money is
        // already back with the payer. The operator has to know that the
        // refund happened outside Konventix, which is what this line and
        // the notice (ADR-0057 point 8) are for.
        Log::warning('Order refunded outside Konventix; ADR-0037 check-in guard could not run.', [
            'reversal_id' => $reversal->id,
            'order_id' => $order->id,
            'provider' => $reversal->provider,
            'provider_reference' => $reversal->provider_reference,
        ]);

        // The amount travels: a dashboard refund can be partial, and
        // `OrderRefunded`'s `whole` is what Registration reads to decide
        // whether to cancel the registration (ADR-0057 point 5). Passing
        // it also gives `IssueCreditNote` the partial total, exactly as
        // `RefundOrder` does for an operator's partial refund.
        $this->recordRefund->handle(
            order: $order,
            reasonCode: $reversal->kind->value,
            reasonNote: $reversal->reason_code,
            amount: $reversal->amount,
        );
    }

    /**
     * `PaymentStatus::Reversed` and the order's revival are written only
     * for the payment's whole amount (ADR-0057 point 1). `Payment::$amount`
     * is guarded numeric because its docblock still promises the wider
     * `string`, and bccomp requires numeric-string.
     */
    private function isWholeAmount(PaymentReversal $reversal, Payment $payment): bool
    {
        $paid = (string) $payment->amount;

        return is_numeric($paid) && bccomp($reversal->amount, $paid, 2) === 0;
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers\Api;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Konventix\Commerce\Actions\RecordPaymentFailure;
use Konventix\Commerce\Actions\RecordPaymentReversal;
use Konventix\Commerce\Actions\RecordPaymentSuccess;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Unzer\UnzerApi;
use Konventix\Commerce\Settlement\Unzer\UnzerPaymentSnapshot;

/**
 * Unzer's notification endpoint (ADR-0039's fetch-back model). Unzer
 * notifies per account — the route carries our `SettlementAccount` ULID,
 * registered as the account's webhook URL at connect time. The body's
 * `paymentId` is an untrusted hint: the payment is re-read from the API
 * with the brand's own key, correlated to our `Payment` row through the
 * fetched `orderId` (our ULID, stamped at Paypage creation), and only that
 * fetched truth decides. A forged call can at worst make us re-read real
 * state.
 *
 * ADR-0057 point 7 narrows the old guard rather than removing it: a
 * notification for a payment that is no longer pending used to return
 * before deciding anything, which dropped the `chargeback` state Unzer
 * moves a settled payment into — the state was a code comment. The guard
 * now protects only the settlement transitions; a succeeded or
 * already-reversed payment reaches the chargeback branch, keyed on the
 * chargeback transaction id from the same fetched payment.
 *
 * Unknown accounts, hints and terminally failed payments answer 200: Unzer
 * retries non-2xx responses, and none of these is fixed by retrying.
 */
class UnzerWebhookController
{
    public function __invoke(
        string $account,
        Request $request,
        UnzerApi $unzer,
        RecordPaymentSuccess $recordSuccess,
        RecordPaymentFailure $recordFailure,
        RecordPaymentReversal $recordReversal,
    ): JsonResponse {
        $row = SettlementAccount::withoutBrandScope()
            ->whereKey($account)
            ->where('provider', SettlementAccount::PROVIDER_UNZER)
            ->first();
        $privateKey = $row?->credentials['private_key'] ?? null;

        $paymentId = $request->input('paymentId');

        if (! is_string($privateKey) || $privateKey === '' || ! is_string($paymentId) || $paymentId === '') {
            return response()->json(['received' => true]);
        }

        $snapshot = $unzer->fetchPayment($paymentId, $privateKey);

        $payment = $snapshot->orderId === null
            ? null
            : Payment::withoutBrandScope()
                ->whereKey($snapshot->orderId)
                ->where('provider', SettlementAccount::PROVIDER_UNZER)
                ->where('settlement_account_id', $row->id)
                ->first();

        if ($payment === null) {
            return response()->json(['received' => true]);
        }

        if ($payment->status === PaymentStatus::Pending) {
            match ($snapshot->state) {
                'completed' => $this->settle($payment, $snapshot->id, $recordSuccess),
                'canceled' => $recordFailure->handle($payment, 'canceled'),
                // pending / partly / payment review: nothing this handler
                // may decide from. `chargeback` on a pending payment is not
                // a reversal either — nothing settled to reverse.
                default => null,
            };

            $payment->refresh();
        }

        $settled = $payment->status === PaymentStatus::Succeeded
            || $payment->status === PaymentStatus::Reversed;

        if ($settled && $snapshot->state === 'chargeback') {
            $this->recordChargebacks($payment, $snapshot, $recordReversal);
        }

        return response()->json(['received' => true]);
    }

    /**
     * Unzer's `chargeback` state says the payment was charged back but not
     * which transaction did it, so the row is keyed on the chargeback
     * transaction's own id (ADR-0057 point 7) — a repeated account-level
     * notification then converges instead of reversing twice.
     */
    private function recordChargebacks(
        Payment $payment,
        UnzerPaymentSnapshot $snapshot,
        RecordPaymentReversal $recordReversal,
    ): void {
        foreach ($snapshot->chargebacks as $chargeback) {
            $recordReversal->handle(
                payment: $payment,
                kind: ReversalKind::Return,
                status: ReversalStatus::Final,
                amount: $chargeback['amount'],
                currency: $chargeback['currency'] !== '' ? $chargeback['currency'] : $payment->currency,
                providerReference: $chargeback['id'],
                // Unzer reports no bank reason code on the chargeback
                // transaction; the state itself is all there is to say.
                reasonCode: null,
                feeAmount: null,
                occurredAt: $this->occurredAt($chargeback['date']),
            );

            $payment->refresh();
        }
    }

    /**
     * Unzer dates a transaction `Y-m-d H:i:s`. A missing or unparsable date
     * must not lose the chargeback, so the moment we read it is the
     * fallback.
     */
    private function occurredAt(?string $date): Carbon
    {
        if ($date === null || $date === '') {
            return Carbon::now();
        }

        try {
            return Carbon::parse($date);
        } catch (InvalidFormatException) {
            return Carbon::now();
        }
    }

    private function settle(Payment $payment, string $unzerPaymentId, RecordPaymentSuccess $recordSuccess): void
    {
        // The money identifier for the eventual refund: the Paypage id
        // started the checkout, but refunds cancel the *payment*.
        $payment->forceFill(['provider_transaction_id' => $unzerPaymentId])->save();

        $recordSuccess->handle($payment);
    }
}

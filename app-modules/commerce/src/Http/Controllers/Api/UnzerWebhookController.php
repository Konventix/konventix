<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Konventix\Commerce\Actions\RecordPaymentFailure;
use Konventix\Commerce\Actions\RecordPaymentSuccess;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Unzer\UnzerApi;

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
 * Unknown accounts, hints and already-terminal payments answer 200: Unzer
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

        if ($payment === null || $payment->status !== PaymentStatus::Pending) {
            return response()->json(['received' => true]);
        }

        match ($snapshot->state) {
            'completed' => $this->settle($payment, $snapshot->id, $recordSuccess),
            'canceled' => $recordFailure->handle($payment, 'canceled'),
            // pending / partly / payment review / chargeback: nothing this
            // handler may decide from.
            default => null,
        };

        return response()->json(['received' => true]);
    }

    private function settle(Payment $payment, string $unzerPaymentId, RecordPaymentSuccess $recordSuccess): void
    {
        // The money identifier for the eventual refund: the Paypage id
        // started the checkout, but refunds cancel the *payment*.
        $payment->forceFill(['provider_transaction_id' => $unzerPaymentId])->save();

        $recordSuccess->handle($payment);
    }
}

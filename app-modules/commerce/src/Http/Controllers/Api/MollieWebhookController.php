<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers\Api;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Konventix\Commerce\Actions\RecordPaymentFailure;
use Konventix\Commerce\Actions\RecordPaymentReversal;
use Konventix\Commerce\Actions\RecordPaymentSuccess;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Mollie\MollieApi;
use Konventix\Commerce\Settlement\Mollie\MolliePaymentSnapshot;

/**
 * Mollie's notification endpoint (ADR-0039's fetch-back model): the request
 * body is an untrusted hint and is deliberately ignored. The route carries
 * our `Payment` ULID; the handler re-reads that payment's *stored* Mollie id
 * from the API with the brand's own key and follows whatever Mollie says.
 * A forged call can therefore, at worst, make us re-read real state.
 *
 * ADR-0057 point 7 narrows the old guard rather than removing it: a
 * notification for a payment that is no longer pending used to return
 * before reading anything, which dropped every chargeback Mollie notifies
 * on the *paid* payment. The guard now protects only the settlement
 * transitions — a succeeded or already-reversed payment is still fetched,
 * and its chargebacks and refunds are read from the same snapshot.
 *
 * Unknown and terminally failed payments answer 200: Mollie retries non-2xx
 * responses, and neither case is fixed by retrying.
 */
class MollieWebhookController
{
    public function __invoke(
        string $payment,
        MollieApi $mollie,
        RecordPaymentSuccess $recordSuccess,
        RecordPaymentFailure $recordFailure,
        RecordPaymentReversal $recordReversal,
    ): JsonResponse {
        $row = Payment::withoutBrandScope()
            ->whereKey($payment)
            ->where('provider', SettlementAccount::PROVIDER_MOLLIE)
            ->first();

        if ($row === null || $row->external_id === null || ! $this->isLive($row)) {
            return response()->json(['received' => true]);
        }

        $account = SettlementAccount::withoutBrandScope()->find($row->settlement_account_id);
        $apiKey = $account?->credentials['api_key'] ?? null;

        if (! is_string($apiKey) || $apiKey === '') {
            // The account was disconnected between checkout and webhook;
            // without its key the truth cannot be read. 200 — retrying
            // will not restore the credentials.
            return response()->json(['received' => true]);
        }

        $snapshot = $mollie->fetchPayment($row->external_id, $apiKey);

        if ($row->status === PaymentStatus::Pending) {
            match ($snapshot->status) {
                'paid' => $recordSuccess->handle($row),
                'failed' => $recordFailure->handle($row, 'failed'),
                'canceled' => $recordFailure->handle($row, 'canceled'),
                'expired' => $recordFailure->handle($row, 'expired', expired: true),
                // open / pending / authorized: nothing decided yet.
                default => null,
            };

            $row->refresh();
        }

        if ($row->status === PaymentStatus::Succeeded || $row->status === PaymentStatus::Reversed) {
            $this->recordReversals($row, $snapshot, $recordReversal);
        }

        return response()->json(['received' => true]);
    }

    /**
     * A payment that failed or expired can never be reversed, and settling
     * it is over: those two answer 200 without an API call, as they always
     * did.
     */
    private function isLive(Payment $row): bool
    {
        return $row->status === PaymentStatus::Pending
            || $row->status === PaymentStatus::Succeeded
            || $row->status === PaymentStatus::Reversed;
    }

    /**
     * Every chargeback is a `return`, final on arrival and keyed by its own
     * `chb_…` id, so a repeated notification converges on one row. A refund
     * is only ours if `RefundOrder` made it, and that Action leaves the
     * order `refunded` inside the same transaction as the provider call —
     * so a refund found beside a still-`paid` order is the operator acting
     * in Mollie's dashboard (ADR-0057 point 1).
     */
    private function recordReversals(
        Payment $row,
        MolliePaymentSnapshot $snapshot,
        RecordPaymentReversal $recordReversal,
    ): void {
        foreach ($snapshot->chargebacks as $chargeback) {
            $recordReversal->handle(
                payment: $row,
                kind: ReversalKind::Return,
                status: ReversalStatus::Final,
                amount: $chargeback['amount'],
                currency: $row->currency,
                providerReference: $chargeback['id'],
                reasonCode: $chargeback['reason'],
                feeAmount: null,
                occurredAt: $this->occurredAt($chargeback['createdAt']),
            );

            $row->refresh();
        }

        if ($snapshot->refunds === []) {
            return;
        }

        $order = Order::withoutBrandScope()->find($row->order_id);

        if ($order === null || $order->status !== OrderStatus::Paid) {
            return;
        }

        foreach ($snapshot->refunds as $refund) {
            $recordReversal->handle(
                payment: $row,
                kind: ReversalKind::ExternalRefund,
                status: ReversalStatus::Final,
                amount: $refund['amount'],
                currency: $row->currency,
                providerReference: $refund['id'],
                reasonCode: $refund['reason'],
                feeAmount: null,
                occurredAt: $this->occurredAt($refund['createdAt']),
            );

            $row->refresh();
        }
    }

    /**
     * Mollie dates every resource in ISO-8601. An unparsable or missing one
     * must not lose the reversal, so the moment we read it is the fallback.
     */
    private function occurredAt(string $createdAt): Carbon
    {
        try {
            return Carbon::parse($createdAt);
        } catch (InvalidFormatException) {
            return Carbon::now();
        }
    }
}

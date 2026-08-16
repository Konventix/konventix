<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Konventix\Commerce\Actions\RecordPaymentFailure;
use Konventix\Commerce\Actions\RecordPaymentSuccess;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Mollie\MollieApi;

/**
 * Mollie's notification endpoint (ADR-0039's fetch-back model): the request
 * body is an untrusted hint and is deliberately ignored. The route carries
 * our `Payment` ULID; the handler re-reads that payment's *stored* Mollie id
 * from the API with the brand's own key and follows whatever Mollie says.
 * A forged call can therefore, at worst, make us re-read real state.
 *
 * Unknown or already-terminal payments answer 200: Mollie retries non-2xx
 * responses, and neither case is fixed by retrying.
 */
class MollieWebhookController
{
    public function __invoke(
        string $payment,
        MollieApi $mollie,
        RecordPaymentSuccess $recordSuccess,
        RecordPaymentFailure $recordFailure,
    ): JsonResponse {
        $row = Payment::withoutBrandScope()
            ->whereKey($payment)
            ->where('provider', SettlementAccount::PROVIDER_MOLLIE)
            ->first();

        if ($row === null || $row->external_id === null || $row->status !== PaymentStatus::Pending) {
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

        match ($snapshot->status) {
            'paid' => $recordSuccess->handle($row),
            'failed' => $recordFailure->handle($row, 'failed'),
            'canceled' => $recordFailure->handle($row, 'canceled'),
            'expired' => $recordFailure->handle($row, 'expired', expired: true),
            // open / pending / authorized: nothing decided yet.
            default => null,
        };

        return response()->json(['received' => true]);
    }
}

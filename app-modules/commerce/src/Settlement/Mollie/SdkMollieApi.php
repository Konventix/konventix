<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Mollie;

use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Chargeback;
use Mollie\Api\Resources\Refund;

/**
 * The real `MollieApi`: a thin pass-through to the Mollie SDK, one client
 * per call because the key is per-brand, not per-process (ADR-0039).
 * Deliberately free of logic; everything decidable lives in
 * `MollieSettlementAdapter`, tested against a fake of the interface.
 */
final class SdkMollieApi implements MollieApi
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function createPayment(array $params, string $apiKey): MolliePaymentSnapshot
    {
        $payment = $this->client($apiKey)->payments->create($params);

        return new MolliePaymentSnapshot(
            id: $payment->id,
            status: $payment->status,
            checkoutUrl: $payment->getCheckoutUrl(),
        );
    }

    public function fetchPayment(string $paymentId, string $apiKey): MolliePaymentSnapshot
    {
        $payment = $this->client($apiKey)->payments->get($paymentId);

        return new MolliePaymentSnapshot(
            id: $payment->id,
            status: $payment->status,
            checkoutUrl: $payment->getCheckoutUrl(),
            // Mollie reports both as amount objects and omits them entirely
            // where nothing was charged back or refunded (SDK v3.13).
            amountChargedBack: $this->amount($payment->amountChargedBack),
            amountRefunded: $this->amount($payment->amountRefunded),
            // Both lists are separate API calls behind `_links`, so they are
            // only followed where the payment says they exist — otherwise
            // every settlement notification would pay for two extra reads.
            chargebacks: $payment->hasChargebacks()
                ? $this->chargebackEntries($payment->chargebacks()->getArrayCopy())
                : [],
            refunds: $payment->hasRefunds()
                ? $this->refundEntries($payment->refunds()->getArrayCopy())
                : [],
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function refundPayment(string $paymentId, array $params, string $apiKey): void
    {
        $this->client($apiKey)->paymentRefunds->createForId($paymentId, $params);
    }

    /**
     * A Mollie amount object is `{currency, value}`; only the value is a
     * decimal string of the shape the domain uses (ADR-0030).
     */
    private function amount(?object $amount): ?string
    {
        $value = $amount->value ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * A chargeback carries a `reason` object with the bank's code; both
     * become the reversal row's `reason_code`, stored verbatim (ADR-0057
     * point 1).
     *
     * @param  array<int|string, Chargeback>  $chargebacks
     * @return list<array{id: string, amount: string, reason: ?string, createdAt: string}>
     */
    private function chargebackEntries(array $chargebacks): array
    {
        $entries = [];

        foreach ($chargebacks as $chargeback) {
            $reason = $chargeback->reason->code ?? null;

            $entries[] = [
                'id' => (string) $chargeback->id,
                'amount' => $this->amount($chargeback->amount) ?? '0.00',
                'reason' => is_string($reason) ? $reason : null,
                'createdAt' => (string) ($chargeback->createdAt ?? ''),
            ];
        }

        return $entries;
    }

    /**
     * A refund has a free-text `description` where a chargeback has a
     * reason code, and — unlike a chargeback — a lifecycle: `status` is
     * carried so the controller can ignore a refund whose money never left
     * (`queued`, `pending`, `processing`, `failed`, `canceled`).
     *
     * @param  array<int|string, Refund>  $refunds
     * @return list<array{id: string, amount: string, reason: ?string, createdAt: string, status: string}>
     */
    private function refundEntries(array $refunds): array
    {
        $entries = [];

        foreach ($refunds as $refund) {
            $entries[] = [
                'id' => (string) $refund->id,
                'amount' => $this->amount($refund->amount) ?? '0.00',
                'reason' => is_string($refund->description) ? $refund->description : null,
                // Non-nullable on a refund, unlike a chargeback's (SDK
                // v3.13).
                'createdAt' => $refund->createdAt,
                'status' => (string) $refund->status,
            ];
        }

        return $entries;
    }

    private function client(string $apiKey): MollieApiClient
    {
        $client = new MollieApiClient;
        $client->setApiKey($apiKey);

        return $client;
    }
}

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
                ? $this->entries($payment->chargebacks()->getArrayCopy())
                : [],
            refunds: $payment->hasRefunds()
                ? $this->entries($payment->refunds()->getArrayCopy())
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
     * Chargebacks and refunds differ in one field: a chargeback carries a
     * `reason` object with the bank's code, a refund a free-text
     * `description`. Both become the reversal row's `reason_code`, stored
     * verbatim (ADR-0057 point 1).
     *
     * @param  array<int|string, Chargeback|Refund>  $resources
     * @return list<array{id: string, amount: string, reason: ?string, createdAt: string}>
     */
    private function entries(array $resources): array
    {
        $entries = [];

        foreach ($resources as $resource) {
            $reason = $resource instanceof Chargeback
                ? ($resource->reason->code ?? null)
                : $resource->description;

            $entries[] = [
                'id' => (string) $resource->id,
                'amount' => $this->amount($resource->amount) ?? '0.00',
                'reason' => is_string($reason) ? $reason : null,
                'createdAt' => (string) ($resource->createdAt ?? ''),
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

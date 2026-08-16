<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Mollie;

use Mollie\Api\MollieApiClient;

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
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function refundPayment(string $paymentId, array $params, string $apiKey): void
    {
        $this->client($apiKey)->paymentRefunds->createForId($paymentId, $params);
    }

    private function client(string $apiKey): MollieApiClient
    {
        $client = new MollieApiClient;
        $client->setApiKey($apiKey);

        return $client;
    }
}

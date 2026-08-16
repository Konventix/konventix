<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Mollie;

/**
 * The seam between the Mollie adapter and the Mollie SDK (ADR-0039).
 * Every call carries `$apiKey` — the *brand's own* key from its
 * `SettlementAccount`, decrypted just-in-time: Mollie has no platform
 * credential at all, which is ADR-0007's "their own account" taken
 * literally.
 */
interface MollieApi
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function createPayment(array $params, string $apiKey): MolliePaymentSnapshot;

    /**
     * The fetch-back half of ADR-0039's webhook model: a Mollie webhook
     * carries only an id, so truth is read from the API with the brand's
     * key — a forged call can at worst make us re-read real state.
     */
    public function fetchPayment(string $paymentId, string $apiKey): MolliePaymentSnapshot;

    /**
     * @param  array<string, mixed>  $params
     */
    public function refundPayment(string $paymentId, array $params, string $apiKey): void;
}

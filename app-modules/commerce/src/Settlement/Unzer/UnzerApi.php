<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Unzer;

/**
 * The seam between the Unzer adapter and the Unzer SDK (ADR-0039). Every
 * call carries `$privateKey` — the *brand's own* key from its
 * `SettlementAccount`, decrypted just-in-time; like Mollie, Unzer has no
 * platform credential at all (ADR-0007).
 */
interface UnzerApi
{
    /**
     * Creates a Paypage v2 in charge mode. `$orderId` is our `Payment`
     * ULID — the correlation key an account-level webhook reads back.
     */
    public function createPaypage(
        float $amount,
        string $currency,
        string $orderId,
        string $returnUrl,
        string $privateKey,
    ): UnzerPaypageSnapshot;

    /**
     * The fetch-back half of ADR-0039's webhook model: the notification
     * body is an untrusted hint carrying a payment id, so truth is read
     * from the API with the brand's key.
     */
    public function fetchPayment(string $paymentId, string $privateKey): UnzerPaymentSnapshot;

    /**
     * The same fetch-back, started from the `orderId` the Paypage was
     * created with — our `Payment` ULID (#191).
     *
     * A payment resource only comes into existence when the payer completes
     * the Paypage, so `fetchPayment()` has nothing to take until settlement
     * and the reconciliation sweep had no way to ask about a *pending*
     * Unzer payment at all. Unzer's own `GET /payments?orderId=` is that
     * way in, and our ULID is already on every Paypage.
     *
     * Null where Unzer holds no payment for that order id, which is the
     * ordinary answer for a Paypage nobody has paid yet — a state, not a
     * failure. Every other error still throws: a rejected key must not read
     * as "the payer did nothing".
     */
    public function fetchPaymentByOrderId(string $orderId, string $privateKey): ?UnzerPaymentSnapshot;

    /**
     * Returns Unzer's own identifier for the cancellation, which the
     * reversal row records (#247).
     */
    public function refundPayment(string $paymentId, float $amount, string $privateKey): string;

    /**
     * Registers `$url` as the account's notification endpoint for all
     * events. Done once at connect time — Unzer notifies per account, so
     * without this registration no payment would ever settle.
     */
    public function registerWebhook(string $url, string $privateKey): void;
}

<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Unzer;

use Konventix\Commerce\Settlement\Unzer\UnzerPaymentSnapshot as PaymentSnapshot;
use RuntimeException;
use UnzerSDK\Constants\ApiResponseCodes;
use UnzerSDK\Constants\TransactionTypes;
use UnzerSDK\Constants\WebhookEvents;
use UnzerSDK\Exceptions\UnzerApiException;
use UnzerSDK\Resources\EmbeddedResources\Paypage\Urls;
use UnzerSDK\Resources\Payment as UnzerPayment;
use UnzerSDK\Resources\V2\Paypage;
use UnzerSDK\Unzer;

/**
 * The real `UnzerApi`: a thin pass-through to the Unzer SDK, one client per
 * call because the key is per-brand, not per-process (ADR-0039).
 * Deliberately free of logic; everything decidable lives in
 * `UnzerSettlementAdapter`, tested against a fake of the interface.
 */
final class SdkUnzerApi implements UnzerApi
{
    public function createPaypage(
        float $amount,
        string $currency,
        string $orderId,
        string $returnUrl,
        string $privateKey,
    ): UnzerPaypageSnapshot {
        $paypage = new Paypage($amount, $currency, TransactionTypes::CHARGE);
        $paypage->setOrderId($orderId);

        $urls = new Urls;
        $urls->setReturnSuccess($returnUrl);
        $urls->setReturnPending($returnUrl);
        $urls->setReturnFailure($returnUrl);
        $urls->setReturnCancel($returnUrl);
        $paypage->setUrls($urls);

        $created = new Unzer($privateKey)->createPaypage($paypage);

        $id = $created->getId();
        $redirectUrl = $created->getRedirectUrl();

        if ($id === null || $redirectUrl === null) {
            throw new RuntimeException('Unzer returned a Paypage without id or redirect URL.');
        }

        return new UnzerPaypageSnapshot(id: $id, redirectUrl: $redirectUrl);
    }

    public function fetchPayment(string $paymentId, string $privateKey): PaymentSnapshot
    {
        $payment = new Unzer($privateKey)->fetchPayment($paymentId);

        return new PaymentSnapshot(
            id: $payment->getId() ?? $paymentId,
            state: $payment->getStateName(),
            orderId: $payment->getOrderId(),
            chargebacks: $this->chargebacks($payment),
        );
    }

    /**
     * `GET /payments?orderId=` behind the SDK's `fetchPaymentByOrderId()`
     * (#191). The order id is our `Payment` ULID, stamped on the Paypage at
     * `createPaypage()`, so a pending payment is addressable long before
     * Unzer has a payment id of its own.
     *
     * Unzer answers a 404 with `API.310.100.003` when it holds no payment
     * for that order id — the Paypage was opened and nobody paid — and that
     * is a state the sweep reads, not an error it reports. Every other
     * `UnzerApiException` propagates: a rejected or expired key has to reach
     * the caller as the failure it is, rather than looking like a payer who
     * never paid, which is the one distinction the hourly pass depends on.
     */
    public function fetchPaymentByOrderId(string $orderId, string $privateKey): ?PaymentSnapshot
    {
        try {
            $payment = new Unzer($privateKey)->fetchPaymentByOrderId($orderId);
        } catch (UnzerApiException $e) {
            if ($this->isPaymentNotFound($e)) {
                return null;
            }

            throw $e;
        }

        return new PaymentSnapshot(
            id: $payment->getId() ?? '',
            state: $payment->getStateName(),
            orderId: $payment->getOrderId() ?? $orderId,
            chargebacks: $this->chargebacks($payment),
        );
    }

    /**
     * Whether Unzer's answer was "I hold no payment for that order id".
     *
     * `UnzerApiException` puts the API's dotted code — `API.310.100.003`
     * here — into `Exception::$code`, which `Throwable::getCode()` still
     * declares `int`; the SDK compares it against `ApiResponseCodes`
     * constants the same way. Loose comparison rather than `===` because of
     * that declared type, and it is exact in practice: since PHP 8 an
     * integer never equals a non-numeric string, so a genuine integer code
     * cannot match this constant by accident.
     */
    private function isPaymentNotFound(UnzerApiException $e): bool
    {
        return in_array($e->getCode(), [ApiResponseCodes::API_ERROR_PAYMENT_NOT_FOUND], false);
    }

    /**
     * The payment's chargeback transactions (SDK v4.0: `getChargebacks()`
     * returns `Chargeback[]`, each an `AbstractTransactionType` with an id,
     * a float amount and a date). The transaction id is what keys the
     * reversal row, so a repeated notification for the same chargeback
     * converges (ADR-0057 point 7).
     *
     * @return list<array{id: string, amount: string, currency: string, date: ?string}>
     */
    private function chargebacks(UnzerPayment $payment): array
    {
        $chargebacks = [];

        foreach ($payment->getChargebacks() as $chargeback) {
            $id = $chargeback->getId();

            if ($id === null) {
                continue;
            }

            $chargebacks[] = [
                'id' => $id,
                // Unzer bills in floats; the domain is decimal strings
                // (ADR-0030), and a chargeback is always two decimals for
                // the currencies MinorUnits accepts.
                'amount' => number_format($chargeback->getAmount() ?? 0.0, 2, '.', ''),
                'currency' => $chargeback->getCurrency(),
                'date' => $chargeback->getDate(),
            ];
        }

        return $chargebacks;
    }

    public function refundPayment(string $paymentId, float $amount, string $privateKey): string
    {
        $client = new Unzer($privateKey);

        // One cancellation per charge the refund had to touch — a payment
        // charged in parts cancels in parts. The first is the one an
        // operator finds the money under, and the id is nullable on a
        // resource the SDK never sent; an empty string says "no reference"
        // rather than inventing one (#247).
        $cancellations = $client->cancelPayment($client->fetchPayment($paymentId), $amount);

        return ($cancellations[0] ?? null)?->getId() ?? '';
    }

    public function registerWebhook(string $url, string $privateKey): void
    {
        new Unzer($privateKey)->createWebhook($url, WebhookEvents::ALL);
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Unzer;

use Konventix\Commerce\Settlement\Unzer\UnzerPaymentSnapshot as PaymentSnapshot;
use RuntimeException;
use UnzerSDK\Constants\TransactionTypes;
use UnzerSDK\Resources\EmbeddedResources\Paypage\Urls;
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
        );
    }

    public function refundPayment(string $paymentId, float $amount, string $privateKey): void
    {
        $client = new Unzer($privateKey);
        $client->cancelPayment($client->fetchPayment($paymentId), $amount);
    }
}

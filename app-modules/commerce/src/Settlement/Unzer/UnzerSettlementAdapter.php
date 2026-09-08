<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Unzer;

use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Exceptions\ProviderRefusedRefundException;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\CheckoutRedirect;
use Konventix\Commerce\Settlement\ProvidesHostedCheckout;
use Konventix\Commerce\Settlement\SettlementAdapter;
use LogicException;
use RuntimeException;
use UnzerSDK\Exceptions\UnzerApiException;

/**
 * Unzer, per ADR-0039: hosted Paypage (v2, charge mode) against the brand's
 * own private key, settlement truth from the fetch-back webhook only.
 * Unzer notifies per *account*, not per payment, so the Paypage carries our
 * `Payment` ULID as its `orderId` — the correlation key the webhook handler
 * reads back from the fetched payment, never from the notification body.
 *
 * Unzer's SDK bills in float major units; the conversion from the domain's
 * decimal strings happens at this boundary and nowhere else.
 */
final class UnzerSettlementAdapter implements ProvidesHostedCheckout, SettlementAdapter
{
    public function __construct(
        private readonly UnzerApi $unzer,
    ) {}

    public function startCheckout(
        SettlementAccount $account,
        Order $order,
        string $returnUrl,
    ): CheckoutRedirect {
        $privateKey = $this->privateKeyOf($account);

        $payment = new Payment([
            'order_id' => $order->id,
            'settlement_account_id' => $account->id,
            'provider' => SettlementAccount::PROVIDER_UNZER,
            'status' => PaymentStatus::Pending,
            'amount' => $order->total_gross,
            'currency' => $order->currency,
        ]);
        // brand_id from the order, not ambient context (ADR-0010).
        $payment->forceFill(['brand_id' => $order->brand_id])->save();

        $paypage = $this->unzer->createPaypage(
            amount: round((float) $order->total_gross, 2),
            currency: $order->currency,
            orderId: $payment->id,
            returnUrl: $returnUrl,
            privateKey: $privateKey,
        );

        $payment->forceFill(['external_id' => $paypage->id])->save();

        return new CheckoutRedirect(paymentId: $payment->id, url: $paypage->redirectUrl);
    }

    public function refund(
        string $accountId,
        Order $order,
        string $amount,
        string $currency,
    ): void {
        $payment = Payment::withoutBrandScope()
            ->where('order_id', $order->id)
            ->where('provider', SettlementAccount::PROVIDER_UNZER)
            ->where('status', PaymentStatus::Succeeded)
            ->latest('received_at')
            ->first();

        $account = SettlementAccount::withoutBrandScope()->find($accountId);

        if ($payment?->provider_transaction_id === null || $account === null) {
            throw new RuntimeException(sprintf(
                'Order [%s] has no refundable Unzer payment on account [%s].',
                $order->id,
                $accountId,
            ));
        }

        // Translated at the seam (#236 fix round 1): `UnzerApiException`
        // extends `Exception`, so a cancellation Unzer refuses used to reach
        // the console as a 500. `getMerchantMessage()` is the sentence
        // written for an operator; the customer-facing one is not.
        try {
            $this->unzer->refundPayment(
                $payment->provider_transaction_id,
                round((float) $amount, 2),
                $this->privateKeyOf($account),
            );
        } catch (UnzerApiException $e) {
            throw ProviderRefusedRefundException::refused(
                SettlementAccount::PROVIDER_UNZER,
                $e->getMerchantMessage() !== '' ? $e->getMerchantMessage() : $e->getMessage(),
                $e,
            );
        }
    }

    /**
     * Never called for Unzer: settlement truth comes exclusively from the
     * fetch-back webhook (ADR-0039). Manual booking goes through
     * `ManualSettlementAdapter` explicitly.
     */
    public function markOrderPaid(
        string $accountId,
        Order $order,
        string $amountPaid,
        string $currency,
        string $settlementMethod,
    ): Order {
        throw new LogicException(
            'Unzer settlement is webhook-driven (ADR-0039); nothing may mark an Unzer order paid by hand through this adapter.',
        );
    }

    public function defaultCheckoutLabel(): string
    {
        return 'Cards, PayPal, SEPA';
    }

    private function privateKeyOf(SettlementAccount $account): string
    {
        $privateKey = $account->credentials['private_key'] ?? null;

        if ($account->provider !== SettlementAccount::PROVIDER_UNZER || ! is_string($privateKey) || $privateKey === '') {
            throw new RuntimeException(sprintf(
                'Settlement account [%s] is not a connected Unzer account.',
                $account->id,
            ));
        }

        return $privateKey;
    }
}

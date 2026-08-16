<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Mollie;

use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\CheckoutRedirect;
use Konventix\Commerce\Settlement\ProvidesHostedCheckout;
use Konventix\Commerce\Settlement\SettlementAdapter;
use LogicException;
use RuntimeException;

/**
 * Mollie, per ADR-0039: hosted payment page against the brand's own API key
 * (decrypted just-in-time from `SettlementAccount.credentials`), settlement
 * truth from the fetch-back webhook only. The webhook URL carries our
 * `Payment` ULID — Mollie's notification body is an untrusted hint, so the
 * handler re-reads the payment from the API and follows *that*.
 */
final class MollieSettlementAdapter implements ProvidesHostedCheckout, SettlementAdapter
{
    public function __construct(
        private readonly MollieApi $mollie,
    ) {}

    public function startCheckout(
        SettlementAccount $account,
        Order $order,
        string $returnUrl,
    ): CheckoutRedirect {
        $apiKey = $this->apiKeyOf($account);

        $payment = new Payment([
            'order_id' => $order->id,
            'settlement_account_id' => $account->id,
            'provider' => SettlementAccount::PROVIDER_MOLLIE,
            'status' => PaymentStatus::Pending,
            'amount' => $order->total_gross,
            'currency' => $order->currency,
        ]);
        // brand_id from the order, not ambient context (ADR-0010).
        $payment->forceFill(['brand_id' => $order->brand_id])->save();

        $created = $this->mollie->createPayment([
            'amount' => [
                'currency' => $order->currency,
                // Mollie wants exactly two decimals as a string — the
                // domain's decimal:2 cast already guarantees that shape.
                'value' => $order->total_gross,
            ],
            'description' => sprintf('Order %s', $order->id),
            'redirectUrl' => $returnUrl,
            'webhookUrl' => route('api.commerce.webhooks.mollie', ['payment' => $payment->id]),
            'metadata' => [
                'konventix_payment_id' => $payment->id,
                'konventix_order_id' => $order->id,
            ],
        ], $apiKey);

        if ($created->checkoutUrl === null) {
            throw new RuntimeException(sprintf(
                'Mollie returned payment [%s] without a checkout URL.',
                $created->id,
            ));
        }

        $payment->forceFill([
            'external_id' => $created->id,
            // Mollie refunds against the same id that correlates webhooks.
            'provider_transaction_id' => $created->id,
        ])->save();

        return new CheckoutRedirect(paymentId: $payment->id, url: $created->checkoutUrl);
    }

    public function refund(
        string $accountId,
        Order $order,
        string $amount,
        string $currency,
    ): void {
        $payment = Payment::withoutBrandScope()
            ->where('order_id', $order->id)
            ->where('provider', SettlementAccount::PROVIDER_MOLLIE)
            ->where('status', PaymentStatus::Succeeded)
            ->latest('received_at')
            ->first();

        $account = SettlementAccount::withoutBrandScope()->find($accountId);

        if ($payment?->external_id === null || $account === null) {
            throw new RuntimeException(sprintf(
                'Order [%s] has no refundable Mollie payment on account [%s].',
                $order->id,
                $accountId,
            ));
        }

        $this->mollie->refundPayment($payment->external_id, [
            'amount' => [
                'currency' => $currency,
                'value' => $amount,
            ],
        ], $this->apiKeyOf($account));
    }

    /**
     * Never called for Mollie: settlement truth comes exclusively from the
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
            'Mollie settlement is webhook-driven (ADR-0039); nothing may mark a Mollie order paid by hand through this adapter.',
        );
    }

    private function apiKeyOf(SettlementAccount $account): string
    {
        $apiKey = $account->credentials['api_key'] ?? null;

        if ($account->provider !== SettlementAccount::PROVIDER_MOLLIE || ! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException(sprintf(
                'Settlement account [%s] is not a connected Mollie account.',
                $account->id,
            ));
        }

        return $apiKey;
    }
}

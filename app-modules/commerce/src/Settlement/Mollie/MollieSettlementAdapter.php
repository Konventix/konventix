<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Mollie;

use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Enums\HostedPaymentState;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Exceptions\ProviderRefusedRefundException;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\CheckoutRedirect;
use Konventix\Commerce\Settlement\ProvidesHostedCheckout;
use Konventix\Commerce\Settlement\SettlementAdapter;
use LogicException;
use Mollie\Api\Exceptions\ApiException as MollieApiException;
use Mollie\Api\Exceptions\MollieException;
use RuntimeException;
use Throwable;

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

    /**
     * #231: the sweep asks before it cancels. `paid` is the same status
     * `MollieWebhookController` settles on. `open`, `failed`, `canceled`
     * and `expired` are decided against the money; `pending` and
     * `authorized` are money still on its way, which is not a reason to
     * cancel and not a settlement either — the next sweep asks again.
     *
     * Every failure is `Unreachable` rather than `NotPaid`: a rejected key
     * or an unreachable Mollie means "we do not know", and not knowing must
     * not cancel an order somebody paid for. Money Mollie says is still on
     * its way is `InFlight`, which the sweep holds for a fortnight rather
     * than a day (fix round 2, review NEW-2).
     */
    public function hostedPaymentState(Payment $payment): HostedPaymentState
    {
        $account = $payment->settlement_account_id === null
            ? null
            : SettlementAccount::withoutBrandScope()->find($payment->settlement_account_id);

        if ($payment->external_id === null || $payment->external_id === '' || $account === null) {
            return HostedPaymentState::Unanswerable;
        }

        try {
            $snapshot = $this->mollie->fetchPayment($payment->external_id, $this->apiKeyOf($account));
        } catch (Throwable $e) {
            Log::warning('Mollie could not be asked whether a pending hosted payment was paid (#231).', [
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'external_id' => $payment->external_id,
                'reason' => $e->getMessage(),
            ]);

            return HostedPaymentState::Unreachable;
        }

        return match ($snapshot->status) {
            'paid' => HostedPaymentState::Paid,
            'pending', 'authorized' => HostedPaymentState::InFlight,
            default => HostedPaymentState::NotPaid,
        };
    }

    public function refund(
        string $accountId,
        Order $order,
        string $amount,
        string $currency,
    ): string {
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

        // Translated at the seam (#236 fix round 1): `ApiException` extends
        // `Exception`, so an unrefundable payment used to reach the console
        // as a 500 rather than as Mollie's own sentence in the form.
        try {
            // Mollie's own `re_…`, carried back for the reversal row (#247).
            return $this->mollie->refundPayment($payment->external_id, [
                'amount' => [
                    'currency' => $currency,
                    'value' => $amount,
                ],
            ], $this->apiKeyOf($account));
        } catch (MollieException $e) {
            // The whole SDK hierarchy, not just `ApiException`: a connection
            // failure is as unrecoverable for the operator as a refusal, and
            // both must read as a sentence rather than a stack trace.
            // `getPlainMessage()` is Mollie's own text; `getMessage()` wraps
            // it in a timestamp, the documentation URL and the request body.
            throw ProviderRefusedRefundException::refused(
                SettlementAccount::PROVIDER_MOLLIE,
                $e instanceof MollieApiException ? $e->getPlainMessage() : $e->getMessage(),
                $e,
            );
        }
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

    public function defaultCheckoutLabel(): string
    {
        return 'PayPal, iDEAL, SEPA, cards';
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

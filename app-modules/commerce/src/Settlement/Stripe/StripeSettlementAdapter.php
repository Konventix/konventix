<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Stripe;

use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\CheckoutRedirect;
use Konventix\Commerce\Settlement\MinorUnits;
use Konventix\Commerce\Settlement\ProvidesHostedCheckout;
use Konventix\Commerce\Settlement\SettlementAdapter;
use Konventix\Platform\Models\Scopes\BrandScope;
use LogicException;
use RuntimeException;

/**
 * Stripe, as ADR-0039 pins it: direct charges on the organizer's own
 * connected account (they are merchant of record, keep the full dashboard,
 * and pay Stripe's fees), hosted Checkout Sessions only, settlement truth
 * exclusively from the signed webhook. No `application_fee_amount`, ever —
 * ADR-0007 forbids deducting platform monetization from ticket revenue.
 *
 * `payment_method_types` is deliberately absent from the session params:
 * omitting it enables Stripe's dynamic payment methods, configured from the
 * organizer's own dashboard.
 */
final class StripeSettlementAdapter implements ProvidesHostedCheckout, SettlementAdapter
{
    /**
     * Tags sessions in the Stripe dashboard; the random-looking suffix is
     * required by the API convention and fixed per integration, not per
     * session.
     */
    private const string INTEGRATION_IDENTIFIER = 'konventix-instant-checkout-qvxbrmda';

    public function __construct(
        private readonly StripeApi $stripe,
    ) {}

    public function startCheckout(
        SettlementAccount $account,
        Order $order,
        string $returnUrl,
    ): CheckoutRedirect {
        $stripeAccountId = $account->provider_account_id;

        if ($account->provider !== SettlementAccount::PROVIDER_STRIPE || $stripeAccountId === null) {
            throw new RuntimeException(sprintf(
                'Settlement account [%s] is not a connected Stripe account.',
                $account->id,
            ));
        }

        $payment = new Payment([
            'order_id' => $order->id,
            'settlement_account_id' => $account->id,
            'provider' => SettlementAccount::PROVIDER_STRIPE,
            'status' => PaymentStatus::Pending,
            'amount' => $order->total_gross,
            'currency' => $order->currency,
        ]);
        // brand_id from the order, not ambient context (ADR-0010): checkout
        // may start from a queue or the public brand host alike.
        $payment->forceFill(['brand_id' => $order->brand_id])->save();

        $session = $this->stripe->createCheckoutSession([
            'mode' => 'payment',
            'client_reference_id' => $payment->id,
            'line_items' => $this->lineItems($order),
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            'metadata' => [
                'konventix_payment_id' => $payment->id,
                'konventix_order_id' => $order->id,
            ],
            'integration_identifier' => self::INTEGRATION_IDENTIFIER,
        ], $stripeAccountId);

        $payment->forceFill(['external_id' => $session->id])->save();

        return new CheckoutRedirect(paymentId: $payment->id, url: $session->url);
    }

    public function refund(
        string $accountId,
        Order $order,
        string $amount,
        string $currency,
    ): void {
        $payment = Payment::withoutBrandScope()
            ->where('order_id', $order->id)
            ->where('provider', SettlementAccount::PROVIDER_STRIPE)
            ->where('status', PaymentStatus::Succeeded)
            ->latest('received_at')
            ->first();

        $account = SettlementAccount::withoutBrandScope()->find($accountId);

        if ($payment?->provider_transaction_id === null || $account?->provider_account_id === null) {
            throw new RuntimeException(sprintf(
                'Order [%s] has no refundable Stripe payment on account [%s].',
                $order->id,
                $accountId,
            ));
        }

        $this->stripe->createRefund([
            'payment_intent' => $payment->provider_transaction_id,
            'amount' => MinorUnits::fromDecimal($amount, $currency),
        ], $account->provider_account_id);
    }

    /**
     * Never called for Stripe: settlement truth comes exclusively from the
     * signed webhook (ADR-0039), which runs `RecordPaymentSuccess` — not
     * through this contract method. Manual booking against a
     * Stripe-connected brand goes through `ManualSettlementAdapter`
     * explicitly.
     */
    public function markOrderPaid(
        string $accountId,
        Order $order,
        string $amountPaid,
        string $currency,
        string $settlementMethod,
    ): Order {
        throw new LogicException(
            'Stripe settlement is webhook-driven (ADR-0039); nothing may mark a Stripe order paid by hand through this adapter.',
        );
    }

    /**
     * One Checkout line per order line, at the snapshotted gross amount
     * (ADR-0017/ADR-0030): tax is already computed and rounded per line by
     * the domain, so the sum of Stripe's lines equals the invoice total to
     * the cent — Stripe is not asked to compute tax again.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lineItems(Order $order): array
    {
        // Relation loads re-apply BrandScope on the related model — escape it
        // per relation (.ai/rules): checkout runs where no brand host exists.
        return $order->lines()
            ->withoutGlobalScope(BrandScope::class)
            ->get()
            ->map(fn (OrderLine $line): array => [
                'price_data' => [
                    'currency' => strtolower($order->currency),
                    'unit_amount' => MinorUnits::fromDecimal($line->line_gross, $order->currency),
                    'product_data' => [
                        'name' => $line->label,
                    ],
                ],
                'quantity' => 1,
            ])
            ->values()
            ->all();
    }
}

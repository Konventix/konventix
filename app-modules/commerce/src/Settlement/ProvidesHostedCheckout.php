<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use Konventix\Commerce\Enums\HostedPaymentState;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;

/**
 * A settlement adapter that can send the payer to a provider-hosted payment
 * page (ADR-0039): Stripe Checkout, Mollie's hosted payment, Unzer's
 * Paypage. Hosted-only is deliberate — no card field ever renders on a
 * Konventix page, keeping the platform in SAQ A the same way ADR-0007 keeps
 * it out of the flow of funds.
 *
 * `manual` and `null` do not implement this: there is nothing to redirect
 * to, and the checkout page must be able to tell "this brand takes online
 * payment" from "this brand books bank transfers by hand" by the interface
 * alone.
 */
interface ProvidesHostedCheckout
{
    /**
     * Creates the pending `Payment` row and the provider-side session, and
     * returns where to send the payer. Settlement truth never comes from the
     * `$returnUrl` — the payer may pay and never return — but every provider
     * requires one; it renders current state, written by the webhook.
     */
    public function startCheckout(
        SettlementAccount $account,
        Order $order,
        string $returnUrl,
    ): CheckoutRedirect;

    /**
     * What the provider says about the hosted session behind `$payment`
     * (#231): the question the expiry sweeps ask before they cancel an
     * order whose payment is still `pending`.
     *
     * Settlement truth still comes from the webhook (ADR-0039) — this
     * answer never *settles* anything by itself; the sweep runs the
     * ordinary `RecordPaymentSuccess` on a `Paid` answer, exactly as the
     * notification would have. It exists because the notification can be
     * lost: on 2026-09-08 a paid Stripe session whose webhook never arrived
     * was cancelled by the cart sweep four minutes later, while the money
     * sat at the provider.
     *
     * Never throws. Every failure is `Undecided`, because not knowing must
     * never be able to cancel an order somebody has paid for.
     */
    public function hostedPaymentState(Payment $payment): HostedPaymentState;

    /**
     * What the checkout button says when the operator wrote nothing — the
     * methods this provider's hosted page typically offers, never a promise:
     * the PSP's dashboard configuration is what actually decides (ADR-0046).
     */
    public function defaultCheckoutLabel(): string;
}

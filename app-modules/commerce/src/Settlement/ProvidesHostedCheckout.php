<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use Konventix\Commerce\Models\Order;
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
     * What the checkout button says when the operator wrote nothing — the
     * methods this provider's hosted page typically offers, never a promise:
     * the PSP's dashboard configuration is what actually decides (ADR-0046).
     */
    public function defaultCheckoutLabel(): string;
}

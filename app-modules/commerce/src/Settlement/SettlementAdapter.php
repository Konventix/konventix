<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use Konventix\Commerce\Models\Order;

/**
 * The one contract every settlement provider — Stripe, Mollie, Unzer, bank
 * transfer, or nothing at all — implements (ADR-0007, SPEC.md "Owns" —
 * "settlement adapters behind a single contract, account-scoped from day
 * one"). The platform never holds funds: money flows directly between the
 * participant and the organizer's own connected PSP account, so every call
 * carries `$accountId` — the brand-scoped identifier of *whose* account is
 * settling this order. No adapter method may act without it.
 *
 * Adapters that can also send a payer to a provider-hosted payment page
 * additionally implement `ProvidesHostedCheckout` (ADR-0039); `manual` and
 * `null` deliberately do not.
 */
interface SettlementAdapter
{
    /**
     * Marks `$order` paid for the brand-scoped `$accountId` and, unless the
     * implementation is a deliberate no-op, emits `OrderPaid`.
     */
    public function markOrderPaid(
        string $accountId,
        Order $order,
        string $amountPaid,
        string $currency,
        string $settlementMethod,
    ): Order;

    /**
     * Moves the money back on the provider side (ADR-0039). A no-op for
     * `manual` — the organizer wires the amount back themselves — and a real
     * API call for PSP adapters. Called by `RefundOrder` after the ADR-0037
     * check-in guard passes; the domain record and the credit note are that
     * Action's job, only the provider side effect belongs here.
     */
    public function refund(
        string $accountId,
        Order $order,
        string $amount,
        string $currency,
    ): void;
}

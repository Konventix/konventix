<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use Konventix\Commerce\Models\Order;

/**
 * The one contract every settlement provider — Stripe, Mollie, bank
 * transfer, or nothing at all — implements (ADR-0007, SPEC.md "Owns" —
 * "settlement adapters behind a single contract, account-scoped from day
 * one"). The platform never holds funds: money flows directly between the
 * participant and the organizer's own connected PSP account, so every call
 * carries `$accountId` — the brand-scoped identifier of *whose* account is
 * settling this order. No adapter method may act without it.
 *
 * `NullSettlementAdapter` and `ManualSettlementAdapter` are the two Phase 1
 * implementations; Stripe Connect Standard and Mollie adapters against real
 * connected accounts are TODO.md Phase 2 backlog.
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
}

<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use Konventix\Commerce\Models\Order;

/**
 * The settlement adapter for a brand with no connected account
 * (`SettlementAccount` connection is TODO.md Phase 2 backlog) — a deliberate
 * no-op. There is nothing to settle against, so it does not change the
 * order and does not emit `OrderPaid`; it exists so the domain model can be
 * exercised without any PSP configured (SPEC.md TODO.md "a null/manual
 * adapter so the domain model can be tested without any PSP").
 */
final class NullSettlementAdapter implements SettlementAdapter
{
    public function markOrderPaid(
        string $accountId,
        Order $order,
        string $amountPaid,
        string $currency,
        string $settlementMethod,
    ): Order {
        return $order;
    }
}

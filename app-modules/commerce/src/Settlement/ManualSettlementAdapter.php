<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Models\Order;

/**
 * First-class manual payment booking (SPEC.md "Owns" — "an organizer marking
 * a bank transfer as received is a normal, supported flow, not a
 * workaround"). Marks the order paid and emits `OrderPaid` — the module's
 * outbound contract (ADR-0006).
 *
 * `$accountId` is accepted and unused beyond being part of the contract: a
 * real `SettlementAccount` record (TODO.md Phase 2 backlog) will eventually
 * validate it, but every adapter call already carries it (ADR-0007).
 */
final class ManualSettlementAdapter implements SettlementAdapter
{
    public function markOrderPaid(
        string $accountId,
        Order $order,
        string $amountPaid,
        string $currency,
        string $settlementMethod,
    ): Order {
        return DB::transaction(function () use ($order, $amountPaid, $currency, $settlementMethod): Order {
            $order->forceFill([
                'status' => OrderStatus::Paid,
                'paid_at' => now(),
            ])->save();

            event(new OrderPaid(
                orderId: $order->id,
                brandId: $order->brand_id,
                settlesType: $order->settles_type,
                settlesId: $order->settles_id,
                amountPaid: $amountPaid,
                currency: $currency,
                paidAt: $order->paid_at->toIso8601String(),
                settlementMethod: $settlementMethod,
            ));

            return $order->refresh();
        });
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\OrderRefunded;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;

/**
 * The **domain leg** of a refund (ADR-0037): the credit note where an
 * invoice exists, `OrderStatus::Refunded`, and `OrderRefunded`. No
 * provider call and no guards — deliberately.
 *
 * Two callers, and the split is exactly the difference between them.
 * `RefundOrder` is the operator's refund: it keeps ADR-0037's check-in
 * guard and the paid guard, moves the money through the provider, and then
 * delegates here. `RecordPaymentReversal` handles the refund the operator
 * made in the provider's own dashboard instead (ADR-0057 point 1,
 * `ReversalKind::ExternalRefund`): the money has already moved, so there is
 * nothing to call and nothing left to guard — the check-in guard cannot
 * run after the fact, which is why that path logs a warning saying so.
 *
 * Escapes BrandScope explicitly (ADR-0010): the reversal caller runs on the
 * stateless api surface, where no brand is resolved from the host.
 */
final class RecordRefund
{
    public function __construct(
        private readonly IssueCreditNote $issueCreditNote,
    ) {}

    public function handle(
        Order $order,
        string $reasonCode,
        ?string $reasonNote = null,
        ?string $amount = null,
    ): Order {
        return DB::transaction(function () use ($order, $reasonCode, $reasonNote, $amount): Order {
            // An order paid at checkout was never invoiced, so there is no
            // document to correct (ADR-0031) — the money still goes back, it
            // simply produces no paperwork.
            $creditNote = Invoice::withoutBrandScope()
                ->where('brand_id', $order->brand_id)
                ->where('order_id', $order->id)
                ->exists()
                    ? $this->issueCreditNote->handle(
                        order: $order,
                        reasonCode: IssueCreditNote::REASON_REFUND,
                        reasonNote: $reasonNote,
                        totalGross: $amount,
                    )
                    : null;

            $order->forceFill(['status' => OrderStatus::Refunded])->save();

            $totalGross = $order->total_gross;

            event(new OrderRefunded(
                orderId: $order->id,
                brandId: $order->brand_id,
                settlesType: $order->settles_type,
                settlesId: $order->settles_id,
                amountRefunded: $amount ?? $totalGross,
                currency: $order->currency,
                creditNoteId: $creditNote?->id,
                reasonCode: $reasonCode,
                refundedAt: now()->toIso8601String(),
                // ADR-0057 point 5: Registration cannot tell a full refund
                // from a partial one from the amount alone. `$amount` is
                // guarded numeric because a caller may pass any string;
                // bccomp itself requires numeric-string.
                whole: $amount === null
                    || (is_numeric($amount) && bccomp($amount, $totalGross, 2) === 0),
            ));

            return $order->refresh();
        });
    }
}

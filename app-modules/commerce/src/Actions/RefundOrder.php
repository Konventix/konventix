<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\OrderRefunded;
use Konventix\Commerce\Exceptions\RefundBlockedByCheckInException;
use Konventix\Commerce\Models\CheckedInAdmission;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use RuntimeException;

/**
 * Returns money for an order, issuing the credit note that documents it
 * (ADR-0031, ADR-0037).
 *
 * **The check-in guard is the point of this Action.** D-COM-15 settles that a
 * checked-in admission blocks the refund, and that Commerce answers that from
 * its **own read model** — never by querying Admission's tables. The guard
 * therefore consults `CheckedInAdmission`, populated by listening to
 * `AdmissionCheckedIn`. Refusal is explicit and reasoned, not a silent no-op:
 * someone attended, so returning the money is a decision a human should have
 * to make deliberately.
 *
 * Cancellation is deliberately not blocked here — only the money flow.
 *
 * ADR-0037 accepts a known gap: an offline device reconciles late (ADR-0033),
 * so a refund executed inside that sync window can slip past a check-in that
 * has happened but not yet arrived. That is a risk the ADR takes knowingly
 * rather than a hole in this guard.
 */
final class RefundOrder
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
            $this->guardPaid($order);
            $this->guardNotCheckedIn($order);

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

            event(new OrderRefunded(
                orderId: $order->id,
                brandId: $order->brand_id,
                settlesType: $order->settles_type,
                settlesId: $order->settles_id,
                amountRefunded: $amount ?? (string) $order->total_gross,
                currency: $order->currency,
                creditNoteId: $creditNote?->id,
                reasonCode: $reasonCode,
                refundedAt: now()->toIso8601String(),
            ));

            return $order->refresh();
        });
    }

    private function guardPaid(Order $order): void
    {
        if ($order->status !== OrderStatus::Paid) {
            throw new RuntimeException(sprintf(
                'Order [%s] is %s; only a paid order can be refunded.',
                $order->id,
                $order->status->value,
            ));
        }
    }

    /**
     * Only registrations can be checked in. An order settling something else
     * has no admissions and therefore nothing to block it.
     */
    private function guardNotCheckedIn(Order $order): void
    {
        if ($order->settles_type !== 'registration') {
            return;
        }

        if (CheckedInAdmission::registrationHasCheckIn($order->brand_id, $order->settles_id)) {
            throw RefundBlockedByCheckInException::forRegistration($order->settles_id);
        }
    }
}

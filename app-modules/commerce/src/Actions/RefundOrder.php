<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Exceptions\RefundBlockedByCheckInException;
use Konventix\Commerce\Models\CheckedInAdmission;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;
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
 * The guards and the provider call are what this Action *is*; the credit
 * note, the status and `OrderRefunded` live in `RecordRefund`, which the
 * `external_refund` reversal path reaches without them (ADR-0057 point 1).
 *
 * ADR-0037 accepts a known gap: an offline device reconciles late (ADR-0033),
 * so a refund executed inside that sync window can slip past a check-in that
 * has happened but not yet arrived. That is a risk the ADR takes knowingly
 * rather than a hole in this guard.
 */
final class RefundOrder
{
    public function __construct(
        private readonly RecordRefund $recordRefund,
        private readonly SettlementAdapterRegistry $registry,
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

            // The PSP side effect (ADR-0039), first and inside the
            // transaction: if the provider refuses the refund, no domain
            // record or credit note may exist claiming the money moved. The
            // inverse gap — provider refunded, then this transaction fails —
            // is accepted; replaying the action converges because providers
            // treat a second refund of the same payment as already done.
            $this->refundThroughProvider($order, $amount);

            // Everything after the money is the refund's domain leg, shared
            // with the reversal path (ADR-0057 point 1): a refund the
            // operator made in the provider's own dashboard has already
            // moved the money and reaches `RecordRefund` directly.
            return $this->recordRefund->handle(
                order: $order,
                reasonCode: $reasonCode,
                reasonNote: $reasonNote,
                amount: $amount,
            );
        });
    }

    /**
     * The money goes back through the provider that took it — resolved from
     * the order's settled payment, not from the brand's *current* live
     * account, which may have changed since. An order with no payment row
     * predates ADR-0039 and was necessarily booked by hand; `manual`'s
     * refund is a no-op either way.
     */
    private function refundThroughProvider(Order $order, ?string $amount): void
    {
        $payment = Payment::withoutBrandScope()
            ->where('order_id', $order->id)
            ->where('status', PaymentStatus::Succeeded)
            ->latest('received_at')
            ->first();

        $this->registry
            ->forProvider($payment->provider ?? SettlementAccount::PROVIDER_MANUAL)
            ->refund(
                accountId: $payment->settlement_account_id ?? SettlementAccount::PROVIDER_MANUAL,
                order: $order,
                amount: $amount ?? (string) $order->total_gross,
                currency: $order->currency,
            );
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
     * Only registrations can be checked in. A direct registration order has
     * one to check; a cart order (ADR-0047) may hold several — every
     * `settles_kind: 'registration'` line names one, and any single
     * checked-in admission among them blocks the whole order the same way,
     * since a cart refund is whole-order (SPEC.md). An order settling
     * neither shape has no admissions and therefore nothing to block it.
     */
    private function guardNotCheckedIn(Order $order): void
    {
        if ($order->settles_type === 'registration') {
            if (CheckedInAdmission::registrationHasCheckIn($order->brand_id, $order->settles_id)) {
                throw RefundBlockedByCheckInException::forRegistration($order->settles_id);
            }

            return;
        }

        if ($order->settles_type !== 'cart') {
            return;
        }

        $registrationIds = OrderLine::withoutBrandScope()
            ->where('order_id', $order->id)
            ->where('settles_kind', 'registration')
            ->whereNotNull('settles_ref')
            ->pluck('settles_ref');

        foreach ($registrationIds as $registrationId) {
            if (CheckedInAdmission::registrationHasCheckIn($order->brand_id, $registrationId)) {
                throw RefundBlockedByCheckInException::forRegistration($registrationId);
            }
        }
    }
}

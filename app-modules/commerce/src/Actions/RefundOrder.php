<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Konventix\Commerce\Actions\Concerns\GuardsCheckedInAdmissions;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Models\Order;
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
 * The order of the three is the whole safety argument (#236): guards, then
 * the provider, then the record — each step only ever runs once the one
 * before it succeeded, and none of them can undo the one before it.
 *
 * What the provider answers travels with the record (#247): the reference it
 * assigns the refund (`re_…`) is stored on a `payment_reversals` row of kind
 * `refund`, so an operator reconciling a statement, the audit entry and the
 * order page all name the same identifier the provider's dashboard does.
 *
 * ADR-0037 accepts a known gap: an offline device reconciles late (ADR-0033),
 * so a refund executed inside that sync window can slip past a check-in that
 * has happened but not yet arrived. That is a risk the ADR takes knowingly
 * rather than a hole in this guard.
 */
final class RefundOrder
{
    // ADR-0057 point 4 gave the same question a second caller (the
    // unpaid-reversal sweep), which skips rather than refuses — the trait
    // owns the definition, this class keeps the refusal.
    use GuardsCheckedInAdmissions;

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
        $this->guardPaid($order);
        $this->guardNotCheckedIn($order);

        // The PSP side effect (ADR-0039) first, and before any domain write:
        // if the provider refuses the refund, no domain record or credit note
        // may exist claiming the money moved. Nothing has been written yet at
        // this point, so a refusal leaves the order exactly as it was.
        [$payment, $providerReference] = $this->refundThroughProvider($order, $amount);

        // Everything after the money is the refund's domain leg, shared with
        // the reversal path (ADR-0057 point 1): a refund the operator made in
        // the provider's own dashboard has already moved the money and
        // reaches `RecordRefund` directly.
        //
        // Deliberately NOT wrapped in a transaction of this Action's own
        // (#236). `RecordRefund` commits the credit note, the status and the
        // provider's act on its own, and announces them only once the
        // outermost transaction has committed. An outer transaction here
        // would simply be that outermost one — harmless now that the
        // guarantee lives in `RecordRefund` rather than in each caller (fix
        // round 1, review finding 1), and still pointless: there is nothing
        // above the record left to make atomic with it.
        return $this->recordRefund->handle(
            order: $order,
            reasonCode: $reasonCode,
            reasonNote: $reasonNote,
            amount: $amount,
            payment: $payment,
            providerReference: $providerReference,
        );
    }

    /**
     * The money goes back through the provider that took it — resolved from
     * the order's settled payment, not from the brand's *current* live
     * account, which may have changed since. An order with no payment row
     * predates ADR-0039 and was necessarily booked by hand; `manual`'s
     * refund is a no-op either way.
     *
     * Returns the payment the money went back through and the provider's own
     * reference for the refund (#247), both of which `RecordRefund` needs to
     * write the reversal row. Either can be null: an order booked by hand has
     * no payment, and `manual`/`null` give no reference.
     *
     * @return array{0: ?Payment, 1: ?string}
     */
    private function refundThroughProvider(Order $order, ?string $amount): array
    {
        $payment = Payment::withoutBrandScope()
            ->where('order_id', $order->id)
            ->where('status', PaymentStatus::Succeeded)
            ->latest('received_at')
            ->first();

        $reference = $this->registry
            ->forProvider($payment->provider ?? SettlementAccount::PROVIDER_MANUAL)
            ->refund(
                accountId: $payment->settlement_account_id ?? SettlementAccount::PROVIDER_MANUAL,
                order: $order,
                amount: $amount ?? (string) $order->total_gross,
                currency: $order->currency,
            );

        return [$payment, $reference];
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
}

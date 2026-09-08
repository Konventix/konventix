<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Actions\Concerns\GuardsCheckedInAdmissions;
use Konventix\Commerce\Actions\Concerns\LocksMoneyRowsInOneOrder;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Exceptions\ProviderRefusedRefundException;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;
use RuntimeException;
use Throwable;

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
 * **Two orders can be refunded** (#6, ADR-0057 point 9). A paid one, which
 * is the whole story above. And a *cancelled* one holding a succeeded
 * payment nobody could apply — the PSP success that landed after a sweep
 * had already called the order off. That refund is the provider leg without
 * a credit note: there is no document to correct and no sale to undo, so
 * the order stays `cancelled`, nothing is announced, and the only records
 * are the money's own — the reversal row with the provider's reference and
 * the payment leaving the unapplied list. Before this the console could
 * show that money and nothing else; the operator had to refund it by hand
 * in the provider's dashboard.
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
    use LocksMoneyRowsInOneOrder;

    public function __construct(
        private readonly RecordRefund $recordRefund,
        private readonly RecordOwnRefund $recordOwnRefund,
        private readonly SettlementAdapterRegistry $registry,
    ) {}

    public function handle(
        Order $order,
        string $reasonCode,
        ?string $reasonNote = null,
        ?string $amount = null,
    ): Order {
        $unapplied = $this->unappliedPayment($order);

        $this->guardRefundable($order, $unapplied);

        // ADR-0037's guard protects a *sale*. Unapplied money paid for
        // nothing — the order was already cancelled when it arrived, the
        // seat was released and no admission came from it — so there is no
        // attendance the money could be weighed against.
        if ($unapplied === null) {
            $this->guardNotCheckedIn($order);
        }

        // Money that was never applied has no sale to undo (ADR-0057
        // point 9): no credit note, because a cancelled order has no
        // document to correct, no `OrderRefunded`, because nothing was
        // refunded *of* the order, and the order stays `cancelled`. Only
        // the money moves — and the payment leaves the unapplied list, so
        // the console stops asking the operator to decide about it. It gets
        // the provider call and the record together, under the payment's own
        // lock (finding 6).
        if ($unapplied !== null) {
            return $this->refundUnapplied($order, $unapplied, $amount);
        }

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

    /**
     * Two orders can be refunded: a paid one, and a cancelled one holding
     * money nobody could apply (ADR-0057 point 9, #6).
     *
     * The second case is the late PSP success: `ExpireCarts` or
     * `ExpireRegistrationOrders` called the order off, the payment landed
     * afterwards, `RecordPaymentSuccess` recorded it as unapplied and told
     * the operator. Until now the console could only *show* it and send the
     * operator to the provider's dashboard, which is the manual step the
     * whole issue was filed against.
     *
     * Pressing the button twice is refused by construction: the first
     * refund moves the payment to `reversed`, so `unappliedPayment()` finds
     * nothing the second time and this guard throws.
     */
    private function guardRefundable(Order $order, ?Payment $unapplied): void
    {
        if ($order->status === OrderStatus::Paid || $unapplied !== null) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Order [%s] is %s and holds no unapplied payment; only a paid order, or one with money that was never applied, can be refunded.',
            $order->id,
            $order->status->value,
        ));
    }

    /**
     * The succeeded payment of a cancelled order — `Payment::unapplied()`'s
     * definition, narrowed to this order. Null for every order that is not
     * in that state, which is what makes it the discriminator between the
     * two refunds this Action performs.
     */
    private function unappliedPayment(Order $order): ?Payment
    {
        return Payment::withoutBrandScope()
            ->unapplied()
            ->where('order_id', $order->id)
            ->orderByDesc('received_at')
            ->first();
    }

    /**
     * The unapplied refund end to end (finding 6): the payment locked, the
     * provider called, the record written — one transaction.
     *
     * The lock is what makes "pressing the button twice is refused" true
     * under concurrency rather than only in sequence. Two console POSTs used
     * to read `succeeded` at the same moment and both call the provider;
     * Stripe rejects a second full refund, but a partial one and both other
     * providers go through twice. The second one in now waits here and finds
     * the payment `reversed`.
     *
     * The provider call happens inside the transaction and after the lock,
     * which is a deliberate trade: the lock is held across a network call —
     * only against other writers of this one payment row, which is precisely
     * the contention we want — and in exchange the money and the record
     * cannot come apart. A throw after the call still leaves the money gone
     * and the record rolled back, so it is logged at error level with the
     * provider's reference: that is an operator's cue, and the only one
     * there can be.
     */
    private function refundUnapplied(Order $order, Payment $unapplied, ?string $amount): Order
    {
        return DB::transaction(function () use ($order, $unapplied, $amount): Order {
            // Order first, then payment (`LocksMoneyRowsInOneOrder`, review
            // NEW-5): this path writes a reversal row too, and the webhook
            // reaches the same three rows from the other side.
            $this->lockOrder((string) $order->getKey());

            $locked = $this->lockPayment($unapplied->id);

            if ($locked === null || $locked->status !== PaymentStatus::Succeeded) {
                throw new RuntimeException(sprintf(
                    'Payment [%s] is no longer unapplied money; nothing was refunded.',
                    $unapplied->id,
                ));
            }

            $providerReference = null;

            try {
                [, $providerReference] = $this->refundThroughProvider($order, $amount);

                return $this->recordUnappliedRefund($order, $locked, $providerReference, $amount);
            } catch (ProviderRefusedRefundException $e) {
                // Nothing moved: the provider refused before touching the
                // money, and the rollback below is the whole correction.
                throw $e;
            } catch (Throwable $e) {
                Log::error('An unapplied refund left the provider but was not recorded; the payment stays on the unapplied list (#6).', [
                    'order_id' => $order->id,
                    'payment_id' => $locked->id,
                    'provider' => $locked->provider,
                    'provider_reference' => $providerReference,
                    'reason' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    /**
     * The domain leg of an unapplied refund: the reversal row that names the
     * provider's reference (#247), and the payment leaving `succeeded`.
     *
     * `PaymentStatus::Reversed` is what takes the row out of
     * `Payment::unapplied()`, so the orders index stops listing the order
     * under "reversals & unapplied money" and the alert on the order page
     * goes away — the operator's decision has been carried out. Written only
     * for the payment's whole amount, exactly as `RecordPaymentReversal`
     * writes it: a partial refund leaves money still lying there, and the
     * console must keep saying so.
     *
     * No credit note, deliberately (ADR-0057 point 9): the cancelled order
     * has no document to correct. The sweeps never cancel an invoiced order
     * — `ExpireRegistrationOrders` excludes them explicitly and a cart order
     * is invoiced only once it is paid — so there is no case here where a
     * document exists and is left uncorrected.
     */
    private function recordUnappliedRefund(
        Order $order,
        Payment $payment,
        ?string $providerReference,
        ?string $amount,
    ): Order {
        $refunded = $amount ?? (string) $order->total_gross;

        if ($providerReference !== null && $providerReference !== '') {
            $this->recordOwnRefund->handle(
                order: $order,
                payment: $payment,
                amount: $refunded,
                providerReference: $providerReference,
            );
        }

        $paid = (string) $payment->amount;

        if (is_numeric($refunded) && is_numeric($paid) && bccomp($refunded, $paid, 2) === 0) {
            $payment->forceFill(['status' => PaymentStatus::Reversed])->save();
        } else {
            Log::warning('Partial refund of unapplied money; the payment stays unapplied for the rest (#6).', [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'refunded' => $refunded,
                'payment_amount' => $paid,
            ]);
        }

        return $order->refresh();
    }
}

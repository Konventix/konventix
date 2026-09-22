<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Actions\Concerns\LocksMoneyRowsInOneOrder;
use Konventix\Commerce\Data\CreditNoteLineSelectionData;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\OrderLineCancelled;
use Konventix\Commerce\Events\OrderRefunded;
use Konventix\Commerce\Jobs\RelayRefundEvents;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;

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
 * A **whole** refund of a *cart* order also emits `OrderLineCancelled` per
 * registration-kind line (whole-branch review finding 3). Without it the
 * money went back and the place, the quota and the admission stayed
 * standing: `CancelRegistrationOnOrderUndone` reacts to `OrderRefunded` only
 * for `settlesType: 'registration'`, because a cart order's `settlesId` is
 * the cart's and cannot say which of its lines to release. That is the same
 * partition `OrderPaid`/`OrderLineSettled` draws, and the same per-line
 * event `CancelOrderForUnpaidReversal` emits for the same reason — so the
 * console's refund button now behaves the same way whichever kind of order
 * paid for the seat. A *partial* refund releases nothing, exactly as
 * `OrderRefunded.whole` already told Registration for a direct order.
 *
 * **A partial refund is its own branch** (D-REG-15, #7). Passing
 * `$reductionKey` says the money went back for named units of a
 * registration rather than for the order: the order stays `paid` because
 * the sale still stands for what the buyer kept, no `OrderLineCancelled`
 * goes out because nothing was cancelled, `OrderRefunded` carries
 * `whole: false` — which Registration already ignores — and the credit note
 * carries the reduction's own key, unique per brand, so the money write is
 * idempotent on it even though the order status can no longer serve as the
 * claim. `$amountNet`/`$amountTax` travel with it so the document states the
 * rate that was actually charged instead of the invoice's totals (#7).
 *
 * **`$creditLines` is the console's partial** (D-0.8.2-1): the operator
 * picked invoice lines and quantities, and `IssueCreditNote` computes the
 * amounts per line rather than being told a sum. It leaves the order `paid`
 * for the same reason a reduction does — the buyer keeps what was not
 * credited, a second partial of the same order is legitimate, and
 * `OrderStatus::Refunded` would tell every list, export and sweep that the
 * sale is over. A selection that comes to the order's whole gross is not a
 * partial at all and takes the ordinary road: `refunded`, the per-line
 * cancellations, `whole: true`.
 *
 * That is deliberately narrower than the pre-existing rule for a partial
 * *amount* with no lines, which still moves the order to `refunded`
 * whatever it comes to. An operator typing a sum, and a provider reporting
 * one dashboard refund back in instalments, both mean the sale is over; a
 * named subset of lines means precisely the opposite, and saying so is the
 * point of naming them.
 *
 * Escapes BrandScope explicitly (ADR-0010): the reversal caller runs on the
 * stateless api surface, where no brand is resolved from the host.
 *
 * **The events leave after the commit, never inside it (#236).** The money
 * has already moved by the time this Action runs — `RefundOrder` calls the
 * provider first — so the credit note, the `refunded` status and the
 * provider's own act have to survive whatever a consumer does with them. On
 * dev they did not: `CancelRegistrationOnOrderLineCancelled` threw inside the
 * console's refund request, the rollback took the credit note and the status
 * with it, and the order sat at `paid` with 11.90 EUR already back on the
 * card.
 *
 * The guarantee is made here rather than in each caller, because it has to
 * hold for **every** caller (fix round 1, review finding 1): dispatching
 * merely after this Action's own `DB::transaction()` still ran the consumers
 * inside `RecordPaymentReversal`'s outer transaction, so the webhook path —
 * the one where no brand is ever resolved — kept the old defect. The events
 * go to `RelayRefundEvents` with `->afterCommit()`, which registers on the
 * same `db.transactions` manager `DB::afterCommit()` uses: it fires when the
 * **outermost** applicable transaction commits, and immediately when there is
 * none. That the relay is a queued job is the other half — see its docblock,
 * and `SPEC.md`'s refund section for the window that remains.
 */
final class RecordRefund
{
    use LocksMoneyRowsInOneOrder;

    /**
     * The order-level reason the per-line cancellation carries — the refund's
     * counterpart of `CancelOrderForUnpaidReversal::REASON_REVERSAL_UNPAID`,
     * and the value `CancelRegistrationOnOrderLineCancelled` keys on.
     * Deliberately not the operator's `RefundReason`: that says *why* the
     * money went back, this says *what happened to the order*.
     */
    public const string REASON_ORDER_REFUNDED = 'order_refunded';

    private const string SETTLES_CART = 'cart';

    private const string KIND_REGISTRATION = 'registration';

    public function __construct(
        private readonly IssueCreditNote $issueCreditNote,
        private readonly RecordOwnRefund $recordOwnRefund,
    ) {}

    /**
     * `$payment` and `$providerReference` come from `RefundOrder` alone
     * (#247): the payment the money went back through and the identifier the
     * provider gave the refund. The reversal path passes neither — its
     * `payment_reversals` row already exists, written by
     * `RecordPaymentReversal` from the provider's own notification.
     *
     * Returns the order as it stands afterwards. A caller that finds the
     * domain leg already done gets the order back untouched rather than an
     * exception: the money moved exactly once either way, and there is
     * nothing for the operator to act on.
     *
     * @param  list<CreditNoteLineSelectionData>|null  $creditLines  D-0.8.2-1's line-and-quantity selection.
     */
    public function handle(
        Order $order,
        string $reasonCode,
        ?string $reasonNote = null,
        ?string $amount = null,
        ?Payment $payment = null,
        ?string $providerReference = null,
        ?string $reductionKey = null,
        ?string $amountNet = null,
        ?string $amountTax = null,
        ?array $creditLines = null,
    ): Order {
        return DB::transaction(function () use ($order, $reasonCode, $reasonNote, $amount, $payment, $providerReference, $reductionKey, $amountNet, $amountTax, $creditLines): Order {
            // **The order row is the claim** (blocker 1), and it is the
            // first lock every money path takes (`LocksMoneyRowsInOneOrder`). Everything below
            // writes paperwork that cannot be taken back — a credit note is
            // immutable (ADR-0031/0048) and the bookkeeping export books it
            // (ADR-0040) — so the decision to write it has to serialize
            // somewhere, and the only thing every caller of this Action
            // shares is the order. Two console POSTs, a console refund
            // racing the provider's own `charge.refunded`, and the two
            // keying the same money differently (`re_…` here, `ch_…` in the
            // webhook's last-resort fallback) all collapse onto this lock:
            // the second one in finds the order already `refunded` and does
            // nothing further.
            //
            // Re-read rather than trusted: the instance came from a
            // controller that fetched it outside any transaction, and the
            // provider call happened before this (#236 keeps the money leg
            // first on purpose).
            $locked = $this->lockOrder((string) $order->getKey());

            if ($locked === null || $locked->status !== OrderStatus::Paid) {
                Log::warning('The refund\'s domain leg was already recorded by whoever got the order first; nothing further written (blocker 1).', [
                    'order_id' => $order->id,
                    'brand_id' => $order->brand_id,
                    'order_status' => $locked?->status->value,
                    'provider_reference' => $providerReference,
                ]);

                return $locked ?? $order->refresh();
            }

            $order = $locked;

            // D-REG-15's claim, narrower still and taken first: a partial
            // refund leaves the order `paid`, so the order lock above lets
            // every replay of the same reduction through. The credit note's
            // `reduction_key` is unique per brand at the database, and this
            // read happens under that same lock, so the second one in stops
            // here — before the reversal row, before the document, before
            // the announcement.
            if ($reductionKey !== null && CreditNote::forReductionKey($order->brand_id, $reductionKey) !== null) {
                Log::info('This quantity reduction has already been credited; nothing further written (D-REG-15).', [
                    'order_id' => $order->id,
                    'brand_id' => $order->brand_id,
                    'reduction_key' => $reductionKey,
                ]);

                return $order->refresh();
            }

            // The reversal row for the provider's own reference (#247), and
            // a second, narrower claim: where both sides key the refund on
            // the same `re_…` the loser stops here rather than at the order
            // lock, so nothing is written at all.
            if (! $this->claimTheRefund($order, $payment, $providerReference, $amount)) {
                Log::warning('The provider announced this refund before it was recorded here; its own notification owns the credit note (#247).', [
                    'order_id' => $order->id,
                    'brand_id' => $order->brand_id,
                    'provider_reference' => $providerReference,
                ]);

                return $order->refresh();
            }

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
                        // Per-unit amounts where the caller computed them
                        // (D-REG-15, #7); null keeps the whole-invoice copy.
                        totalNet: $amountNet,
                        totalTax: $amountTax,
                        reductionKey: $reductionKey,
                        // The console's line-and-quantity pick (D-0.8.2-1);
                        // when it is given it computes the three amounts
                        // itself and the ones above are not consulted.
                        lines: $creditLines,
                    )
                    : null;

            $totalGross = $order->total_gross;

            $whole = $reductionKey === null
                && ($amount === null
                    || (is_numeric($amount) && bccomp($amount, $totalGross, 2) === 0));

            // **A unit refund leaves the order `paid`** (D-REG-15), and only
            // that one does. The sale still stands: the buyer kept units,
            // the invoice is still owed for them, and `OrderStatus::Refunded`
            // would tell every list, export and sweep otherwise. It is also
            // what allows a second reduction on the same order — the order
            // lock above refuses anything that is no longer `paid`, so
            // flipping the status here would make the first reduction the
            // last.
            //
            // Every other partial refund keeps the behaviour it has always
            // had and moves the order to `refunded` regardless of amount:
            // an operator's partial, and a dashboard refund the provider
            // reports back in instalments (`commerce:reconcile-payments`
            // records two 60.00/59.00 legs of one 119.00 order), both mean
            // the sale is over even though no single leg equals the total.
            // `OrderRefunded.whole` is what tells Registration the
            // difference, and it always has been.
            if ($reductionKey === null && ! ($creditLines !== null && ! $whole)) {
                $order->forceFill(['status' => OrderStatus::Refunded])->save();
            }

            $refundedAt = now()->toIso8601String();

            /** @var list<object> $events */
            $events = [];

            $events[] = new OrderRefunded(
                orderId: $order->id,
                brandId: $order->brand_id,
                settlesType: $order->settles_type,
                settlesId: $order->settles_id,
                amountRefunded: $amount ?? $totalGross,
                currency: $order->currency,
                creditNoteId: $creditNote?->id,
                reasonCode: $reasonCode,
                refundedAt: $refundedAt,
                // ADR-0057 point 5: Registration cannot tell a full refund
                // from a partial one from the amount alone. `$amount` is
                // guarded numeric because a caller may pass any string;
                // bccomp itself requires numeric-string.
                whole: $whole,
            );

            if ($whole) {
                $events = [...$events, ...$this->orderLineCancelledEvents($order, $refundedAt)];
            }

            // Queued from inside the transaction but pushed only once the
            // outermost one commits (#236): `->afterCommit()` hands the push
            // to `db.transactions`, which also drops it if that transaction
            // rolls back — so an announcement can never outlive a refund that
            // was not recorded, and a consumer can never unrecord one.
            RelayRefundEvents::dispatch($order->id, $events)->afterCommit();

            return $order->refresh();
        });
    }

    /**
     * Writes the provider's own identifier for this refund on a row of its
     * own (#247) and answers whether **this** call is the one that did it.
     *
     * `false` means the row was already there: the provider's notification
     * won the race and its `RecordPaymentReversal` is issuing the credit
     * note, moving the order and announcing it, so this caller must not do
     * any of that a second time (fix round 2, review NEW-3).
     *
     * `true` where there is nothing to claim *here* — a `manual` refund the
     * organizer wires back themselves has no reference, and an order booked
     * by hand has no payment. Those paths are claimed by the order lock
     * above instead (blocker 1), which is the claim that holds for every
     * caller; this one only adds the narrower "same `re_…`, so write
     * nothing" case.
     */
    private function claimTheRefund(
        Order $order,
        ?Payment $payment,
        ?string $providerReference,
        ?string $amount,
    ): bool {
        if ($payment === null || $providerReference === null || $providerReference === '') {
            return true;
        }

        return $this->recordOwnRefund->handle(
            order: $order,
            payment: $payment,
            // A partial refund records the part that went back, exactly as
            // the credit note below documents the part.
            amount: $amount ?? (string) $order->total_gross,
            providerReference: $providerReference,
        )->wasRecentlyCreated;
    }

    /**
     * The registration lines a wholly refunded cart order gives back, one
     * event each, built but not dispatched — the caller sends them after the
     * commit (#236). `CancelOrderForUnpaidReversal::emitOrderLineCancelled()`'s
     * twin, down to the `position` ordering and the registration-kind filter.
     *
     * A direct registration order is skipped: `OrderRefunded` already names
     * the one thing it settles, and emitting both would cancel it twice.
     * `cancelledByUserId` is null because `OrderRefunded` carries no actor
     * either — the two paths must write the same audit row.
     *
     * @return list<OrderLineCancelled>
     */
    private function orderLineCancelledEvents(Order $order, string $refundedAt): array
    {
        if ($order->settles_type !== self::SETTLES_CART) {
            return [];
        }

        $lines = OrderLine::withoutBrandScope()
            ->where('order_id', $order->id)
            ->where('settles_kind', self::KIND_REGISTRATION)
            ->whereNotNull('settles_ref')
            ->orderBy('position')
            ->get();

        $events = [];

        foreach ($lines as $line) {
            if ($line->settles_kind === null || $line->settles_ref === null) {
                continue;
            }

            $events[] = new OrderLineCancelled(
                orderId: $order->id,
                brandId: $order->brand_id,
                kind: $line->settles_kind,
                refId: $line->settles_ref,
                orderRequestKey: $order->request_key,
                reasonCode: self::REASON_ORDER_REFUNDED,
                cancelledByUserId: null,
                cancelledAt: $refundedAt,
            );
        }

        return $events;
    }
}

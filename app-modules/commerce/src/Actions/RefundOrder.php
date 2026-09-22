<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Actions\Concerns\GuardsCheckedInAdmissions;
use Konventix\Commerce\Actions\Concerns\LocksMoneyRowsInOneOrder;
use Konventix\Commerce\Data\CreditNoteLineSelectionData;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Exceptions\ProviderRefusedRefundException;
use Konventix\Commerce\Exceptions\RefundBlockedByCheckInException;
use Konventix\Commerce\Exceptions\RefundEventRelayFailedException;
use Konventix\Commerce\Models\CheckedInAdmission;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;
use Konventix\Compliance\Audit\AuditRecorder;
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
 * **And a third, partial one** (D-REG-15, #7): a confirmed registration
 * bought fewer units, so the money for exactly those units goes back while
 * the order stays `paid` and the rest of the sale stands. That refund is
 * keyed on the reduction rather than on the order — the order status can no
 * longer serve as the claim once a second partial is legitimate — and it
 * carries its own net/tax split, computed from the order line's rate, so the
 * Gutschrift states the rate that was actually charged. Its check-in guard
 * is per admission rather than per registration: one attendee among five
 * does not block the money for four people who never came.
 *
 * **And a fourth, the operator's own partial** (#7, D-0.8.2-1): the console
 * names invoice lines and quantities, `ResolveCreditNoteLines` computes what
 * they come to at each line's snapshotted rate, and that — not a sum anybody
 * typed — is both what the provider is asked to send back and what the
 * Gutschrift documents. The selection is resolved **before** the provider is
 * called, so a pick that cannot be credited (a line already credited in full,
 * a line that is not on this order) refuses while nothing has moved;
 * `IssueCreditNote` resolves it again inside the transaction that holds the
 * order lock, which is the answer that counts. Like the reduction, it leaves
 * the order `paid` unless the selection comes to the whole gross.
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
 * **Every refund audits itself** (#250). The `commerce.order.refunded`
 * entry used to be written by the console controller, so a refund from the
 * reconcile sweep or a console command left no trail; it is written here
 * now, and a caller contributes nothing but the actor. The one refund that
 * does not write it is D-REG-15's unit refund, which has its own entry with
 * its own columns (`RefundUnitsOnRegistrationQuantityReduced`).
 *
 * ADR-0037 accepts a known gap: an offline device reconciles late (ADR-0033),
 * so a refund executed inside that sync window can slip past a check-in that
 * has happened but not yet arrived. That is a risk the ADR takes knowingly
 * rather than a hole in this guard.
 */
final class RefundOrder
{
    /**
     * The audit entry every whole refund leaves, whoever asked for it
     * (#250). It used to be written by `OrderAdminController::refund()`, so
     * a refund from the reconcile sweep or a console command left no trail
     * at all — the one caller that had a `request()` behind it was also the
     * only one that recorded who did this and why.
     */
    public const string AUDIT_REFUNDED = 'commerce.order.refunded';

    /**
     * D-0.8.2-3: the `provider_reference` a refund with no provider
     * reference of its own is keyed on — `manual:<order>`.
     *
     * `payment_reversals.provider_reference` stays NOT NULL (decision of
     * 2026-09-08) because the unique `(provider, provider_reference)` key is
     * the table's whole convergence mechanism and Postgres treats NULLs as
     * distinct. So the row a manual refund needs in order to be visible to
     * reconciliation has to carry *some* key, and that key must be one no
     * provider can ever hand us.
     *
     * The colon is what makes that true. Every identifier the three
     * providers assign is drawn from `[A-Za-z0-9_-]`: Stripe's `re_…`/`dp_…`
     * (prefix, underscore, base62), Mollie's `re_…`/`chb_…` (prefix,
     * underscore, alphanumeric) and Unzer's cancellation ids (`s-cnl-1`,
     * letters, digits and hyphens). None of the three can contain `:`, so no
     * real reference can collide with a synthetic one — and the order's ULID
     * after it keeps two orders' manual refunds apart.
     */
    private const string MANUAL_REFERENCE_PREFIX = 'manual:';

    private const string KIND_REGISTRATION = 'registration';

    // ADR-0057 point 4 gave the same question a second caller (the
    // unpaid-reversal sweep), which skips rather than refuses — the trait
    // owns the definition, this class keeps the refusal.
    use GuardsCheckedInAdmissions;
    use LocksMoneyRowsInOneOrder;

    public function __construct(
        private readonly RecordRefund $recordRefund,
        private readonly RecordOwnRefund $recordOwnRefund,
        private readonly SettlementAdapterRegistry $registry,
        private readonly AuditRecorder $auditRecorder,
        private readonly ResolveCreditNoteLines $resolveCreditNoteLines,
    ) {}

    /**
     * `$reductionKey`, `$amountNet` and `$amountTax` are D-REG-15's partial
     * refund (see the class docblock's third refund). Null everywhere else.
     *
     * `$actorUserId` is the only thing a caller still contributes to the
     * audit entry (#250): the console passes the operator, a sweep or a
     * console command passes nothing, and `AuditRecorder` is left to record
     * an actorless act rather than invent one.
     *
     * @param  list<CreditNoteLineSelectionData>|null  $creditLines  D-0.8.2-1's line-and-quantity selection; it decides `$amount` rather than being checked against one.
     */
    public function handle(
        Order $order,
        string $reasonCode,
        ?string $reasonNote = null,
        ?string $amount = null,
        ?string $reductionKey = null,
        ?string $amountNet = null,
        ?string $amountTax = null,
        ?string $registrationId = null,
        ?int $units = null,
        ?string $actorUserId = null,
        ?array $creditLines = null,
    ): Order {
        // **Before the provider, not after** (D-REG-15). The money leg runs
        // first on purpose (#236), so the only place a replay can be stopped
        // without sending the money twice is here, ahead of it. The credit
        // note's `reduction_key` is unique per brand, and `RecordRefund`
        // asks again under the order lock for the concurrent case this
        // unlocked read cannot see.
        if ($reductionKey !== null && CreditNote::forReductionKey($order->brand_id, $reductionKey) !== null) {
            return $order->refresh();
        }

        $unapplied = $this->unappliedPayment($order);

        $this->guardRefundable($order, $unapplied);

        // **Before the provider** (D-0.8.2-1). The amount a line selection
        // comes to is computed here rather than taken from the caller, and
        // the same call is what refuses a selection that would over-credit
        // a line or name one that is not on this order. Both matter in this
        // order: the money leg runs first (#236), so a refusal that only
        // happened at document time would leave the payer's money returned
        // and no Gutschrift to explain it. `IssueCreditNote` asks again
        // under the order lock for the concurrent case this unlocked read
        // cannot see — the same two-step the reduction key already uses.
        if ($creditLines !== null) {
            $amount = $this->resolveCreditNoteLines->handle($order, $creditLines)->totalGross;
        }

        // ADR-0037's guard protects a *sale*. Unapplied money paid for
        // nothing — the order was already cancelled when it arrived, the
        // seat was released and no admission came from it — so there is no
        // attendance the money could be weighed against.
        if ($unapplied === null && $reductionKey === null) {
            $this->guardNotCheckedIn($order);
        }

        // ADR-0037's own consequence, made real: "if a future partial-refund
        // flow refunds *unused* admissions of a partially checked-in group,
        // the guard needs per-admission granularity; the read model's
        // per-admission keying already permits that." A reduction refunds
        // named units, so one attendee among five no longer blocks the money
        // for the four who never came — what is refused is a refund of more
        // units than the registration has admissions nobody used.
        if ($reductionKey !== null && $registrationId !== null && $units !== null) {
            $this->guardEnoughUnusedAdmissions($order, $registrationId, $units);
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
            $refunded = $this->refundUnapplied($order, $unapplied, $amount);

            $this->recordAudit(
                order: $refunded,
                reasonCode: $reasonCode,
                reasonNote: $reasonNote,
                amount: $amount,
                unapplied: true,
                announcementFailed: false,
                actorUserId: $actorUserId,
            );

            return $refunded;
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
        $relayFailure = null;

        try {
            $refunded = $this->recordRefund->handle(
                order: $order,
                reasonCode: $reasonCode,
                reasonNote: $reasonNote,
                amount: $amount,
                payment: $payment,
                providerReference: $providerReference,
                reductionKey: $reductionKey,
                amountNet: $amountNet,
                amountTax: $amountTax,
                creditLines: $creditLines,
            );
        } catch (RefundEventRelayFailedException $e) {
            // The opposite of a refusal (#236 fix round 1, NEW-1): the record
            // is committed and the money is with the payer, only the fan-out
            // inside Konventix failed — `RelayRefundEvents` runs inline under
            // a `sync` queue, and its throw escapes the commit that already
            // happened. Moving the audit write into this Action (#250) means
            // this arm has to stay here rather than in the controller: who
            // sent the money back is not conditional on whether a downstream
            // listener behaved. Recorded, then rethrown, so the caller can
            // still say the release needs looking at.
            $relayFailure = $e;
            $refunded = $order->refresh();
        }

        // D-REG-15's unit refund is not an order refund and leaves the order
        // `paid`; `RefundUnitsOnRegistrationQuantityReduced` writes
        // `commerce.order.units-refunded` for it instead, with the units, the
        // reduction and the net/tax split this entry has no columns for.
        if ($reductionKey === null) {
            $this->recordAudit(
                order: $refunded,
                reasonCode: $reasonCode,
                reasonNote: $reasonNote,
                amount: $amount,
                unapplied: false,
                announcementFailed: $relayFailure !== null,
                actorUserId: $actorUserId,
            );
        }

        if ($relayFailure !== null) {
            throw $relayFailure;
        }

        return $refunded;
    }

    /**
     * The `provider_reference` a refund with no reference of its own is
     * keyed on (D-0.8.2-3, #250) — `manual:<order>`.
     *
     * Public because the operator console and the reconciliation report both
     * need to recognise the shape rather than restate it, and because a test
     * asserting the row must name the same string the writer used.
     */
    public static function manualReference(Order $order): string
    {
        return self::MANUAL_REFERENCE_PREFIX.$order->getKey();
    }

    /**
     * The refund's audit entry (#250), written by the Action so that every
     * caller leaves one — the console, the reconcile sweep, a console
     * command, a test.
     *
     * After the money and the record, never inside either: an audit write is
     * not a business transaction and must not take part in a rollback
     * (`AuditRecorder`'s own docblock). The reason and the note are recorded
     * here in full — this row, not the credit note's document, is where an
     * operator later reads why the money went back.
     */
    private function recordAudit(
        Order $order,
        string $reasonCode,
        ?string $reasonNote,
        ?string $amount,
        bool $unapplied,
        bool $announcementFailed,
        ?string $actorUserId,
    ): void {
        $this->auditRecorder->record(
            action: self::AUDIT_REFUNDED,
            subjectId: $order->getKey(),
            brandId: $order->brand_id,
            context: [
                'reason_code' => $reasonCode,
                'reason_note' => $reasonNote,
                'amount' => $amount ?? (string) $order->total_gross,
                'currency' => $order->currency,
                // The provider's own identifier for the money going back
                // (#247), read off the row just written — or the synthetic
                // `manual:<order>` where the provider gave none
                // (D-0.8.2-3). Null only for a refund of a *paid* order that
                // no provider took money for, which writes no row at all.
                'provider_reference' => $this->providerRefundReference($order),
                // Nothing of the order was refunded on the unapplied path: it
                // stays cancelled, no credit note is written, and only the
                // money that could never be applied went back (#6).
                'unapplied' => $unapplied,
                // Whether the seat, the quota and the admission were released
                // with the money. An operator reconstructing "why is this
                // registration still confirmed" reads it here rather than
                // correlating a queue failure by timestamp.
                'announcement_failed' => $announcementFailed,
            ],
            actorUserId: $actorUserId,
        );
    }

    /**
     * The reference the provider gave the refund this call just made (#247).
     * Newest first, because an order can be refunded more than once over its
     * life — a partial refund followed by the rest.
     */
    private function providerRefundReference(Order $order): ?string
    {
        /** @var string|null $reference */
        $reference = PaymentReversal::withoutBrandScope()
            ->where('order_id', $order->getKey())
            // Either kind is this refund's own reference (`re_…`): `refund`
            // when we recorded it, `external_refund` when the provider's own
            // notification won the race and recorded it first (review NEW-3).
            // What the operator needs is the identifier, not which path
            // wrote it.
            ->whereIn('kind', [ReversalKind::Refund->value, ReversalKind::ExternalRefund->value])
            // ULIDs are monotone, unlike two `created_at` in the same clock
            // tick — an order can be refunded more than once over its life.
            ->orderByDesc('id')
            ->value('provider_reference');

        return $reference;
    }

    /**
     * The per-admission half of ADR-0037's guard (D-REG-15): how many
     * admissions of this registration were checked in, against how many
     * units the refund is for.
     *
     * The order line's `quantity` is how many units were sold; a refund of
     * `$units` is legitimate exactly while `sold - used >= units`.
     * Admission has already refused the reduction when fewer than that many
     * admissions were free, so in practice this never fires first — it is
     * the money rail enforcing the rule itself rather than trusting
     * Admission's discipline, which is what ADR-0037 decided against
     * "Commerce reports, Registration decides".
     */
    private function guardEnoughUnusedAdmissions(Order $order, string $registrationId, int $units): void
    {
        $sold = (int) OrderLine::withoutBrandScope()
            ->where('order_id', $order->id)
            ->where('settles_kind', self::KIND_REGISTRATION)
            ->where('settles_ref', $registrationId)
            ->sum('quantity');

        $used = CheckedInAdmission::countForRegistration($order->brand_id, $registrationId);

        if ($sold - $used < $units) {
            throw RefundBlockedByCheckInException::forUnitsOfRegistration($registrationId, $units, max(0, $sold - $used));
        }
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
     *
     * **A refund with no provider reference gets a row too** (D-0.8.2-3,
     * #250). Until now the row was written only where the provider named the
     * money, so a `manual` refund — the organizer wiring the money back
     * themselves — moved the payment off the unapplied list and left nothing
     * behind: reconciliation saw a succeeded payment turn into a `reversed`
     * one with no record of where the money went. It now gets the same row
     * every other refund gets, keyed on `manual:<order>`
     * (`manualReference()`), which is a shape no provider can assign. The
     * unique `(provider, provider_reference)` key is still the convergence
     * mechanism, so a repeated run finds the row instead of writing a second
     * one.
     */
    private function recordUnappliedRefund(
        Order $order,
        Payment $payment,
        ?string $providerReference,
        ?string $amount,
    ): Order {
        $refunded = $amount ?? (string) $order->total_gross;

        $this->recordOwnRefund->handle(
            order: $order,
            payment: $payment,
            amount: $refunded,
            providerReference: $providerReference !== null && $providerReference !== ''
                ? $providerReference
                : self::manualReference($order),
        );

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

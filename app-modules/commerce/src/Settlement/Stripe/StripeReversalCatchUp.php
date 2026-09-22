<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Stripe;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use Konventix\Commerce\Settlement\MinorUnits;

/**
 * What Stripe's API says has taken a payment's money back, asked about one
 * payment intent and mapped onto the reversal vocabulary of ADR-0057
 * point 1 (#235).
 *
 * **Why this exists at all.** Stripe does not guarantee the order of its
 * events. On the dispute test card `charge.dispute.created` arrives one
 * second *before* `checkout.session.completed`, so at dispute time the
 * `Payment` row still carries no `provider_transaction_id`,
 * `StripeWebhookController::paymentForTransaction()` finds nothing, the
 * endpoint answers 200 — the only safe answer, a non-2xx would make Stripe
 * retry every unknown event for days — and Stripe never sends that event
 * again. The dispute was lost silently while Stripe had already withdrawn
 * the money.
 *
 * Two callers ask the same question at two moments, which is the whole fix:
 * `StripeWebhookController::settled()` asks once, right after it has
 * recorded the payment intent the dispute would have named, and
 * `ReconcilePayments`' settled pass asks again for succeeded payments of
 * the last days — the sweep #71 built for exactly the notification that
 * never arrived. Both then record through `RecordPaymentReversal`, which is
 * idempotent under the unique `(provider, provider_reference)` key, so
 * asking twice records once.
 *
 * This class only *reads and maps*; recording is the caller's, because what
 * a new row means differs: the webhook has just settled a payment, while
 * the sweep has to log and notify an operator that a notification was lost.
 */
final class StripeReversalCatchUp
{
    /**
     * What a Stripe dispute status that **closes** a dispute means for the
     * reversal row — the one map, read by `StripeWebhookController::
     * disputeClosed()` for the signed event and by this class for the
     * fetched list (#249).
     *
     * `lost` is money gone: the reversal becomes final and the receivable
     * revives (ADR-0057 point 3). `won` is money that stayed, so the row is
     * withdrawn. `warning_closed` — an early fraud warning closed without
     * ever becoming a dispute — is the same fact and gets the same answer;
     * the webhook used to acknowledge it without touching the row, which
     * left an `Open` reversal for a warning that had been closed standing in
     * the operator's work list, the same defect #249 filed against the
     * sweep. Every other status leaves the dispute running.
     *
     * One map rather than two because both sides move real money and a
     * drift between them would be silent.
     *
     * @var array<string, ReversalStatus>
     */
    public const array CLOSING_STATUSES = [
        'lost' => ReversalStatus::Final,
        'won' => ReversalStatus::Withdrawn,
        'warning_closed' => ReversalStatus::Withdrawn,
    ];

    /**
     * The reversals Stripe holds against `$payment`'s payment intent, in
     * the shape `RecordPaymentReversal::handle()` takes.
     *
     * A payment with no intent recorded has nothing to ask about — the
     * session never produced one, so no money moved through it.
     *
     * @return list<array{kind: ReversalKind, status: ReversalStatus, amount: string, currency: string, providerReference: string, reasonCode: ?string, feeAmount: ?string, occurredAt: Carbon}>
     */
    public function reversalsFor(Payment $payment): array
    {
        $intent = $payment->provider_transaction_id;

        if ($intent === null || $intent === '') {
            return [];
        }

        // Resolved here rather than injected, the way `ReconcilePayments`
        // resolves it: a deployment with no Stripe credentials must not fail
        // to *construct* a caller that may never ask anything.
        $answer = app(StripeApi::class)->fetchPaymentIntentReversals($intent);

        $reversals = [];

        foreach ($answer['disputes'] as $dispute) {
            $mapped = $this->dispute($dispute, $payment);

            if ($mapped !== null) {
                $reversals[] = $mapped;
            }
        }

        foreach ($answer['refunds'] as $refund) {
            $mapped = $this->refund($refund, $payment);

            if ($mapped !== null) {
                $reversals[] = $mapped;
            }
        }

        return $reversals;
    }

    /**
     * A dispute read back from the API, unlike one read off
     * `charge.dispute.created`, cannot say whether it was *born* lost —
     * Stripe's shape for a SEPA return — or lost weeks later. It is
     * therefore always a `dispute`, never a `return`; both revive the
     * receivable identically (ADR-0057 point 1), and the kind only says
     * which story the operator reads.
     *
     * A dispute Stripe reports as **closed** is where #249 lives. `won` and
     * `warning_closed` used to be skipped outright, on the grounds that the
     * money stayed and there was nothing to revive. True for a dispute this
     * deployment never heard of; wrong for one it did — a
     * `charge.dispute.closed` lost on the way here (the endpoint was
     * unreachable, #232) left the `Open` row its `created` event had written
     * standing in the operator's work list for ever. A closing status now
     * closes such a row, and is still skipped where there is none.
     *
     * Neither the status it closes to nor the treatment of the receivable is
     * decided here: `CLOSING_STATUSES` is the map
     * `StripeWebhookController::disputeClosed()` reads too, and the
     * transition is `RecordPaymentReversal`'s, the same Action the webhook
     * calls. Money is moved by one piece of code, not by two that have to
     * agree.
     *
     * @param  array{id: string, status: string, amount: int, currency: string, reason: ?string, fee: ?int, created: ?int}  $dispute
     * @return array{kind: ReversalKind, status: ReversalStatus, amount: string, currency: string, providerReference: string, reasonCode: ?string, feeAmount: ?string, occurredAt: Carbon}|null
     */
    private function dispute(array $dispute, Payment $payment): ?array
    {
        $closesAs = self::CLOSING_STATUSES[$dispute['status']] ?? null;

        if ($closesAs === null) {
            // Still running. `occurred_at` is when it began, which is what
            // the operator's list shows.
            return $this->mapped($dispute, $payment, ReversalStatus::Open, $this->occurredAt($dispute['created']));
        }

        $existing = PaymentReversal::forProviderReference($payment->provider, $dispute['id']);

        if ($existing === null) {
            // Nothing to close. A dispute *lost* while this deployment never
            // heard of it is still a reversal that has to be recorded — #235's
            // own case — and one that was won or warned-and-closed cost
            // nothing, so inventing a withdrawn row would tell an operator
            // about money that never left.
            return $closesAs === ReversalStatus::Final
                ? $this->mapped($dispute, $payment, ReversalStatus::Final, $this->occurredAt($dispute['created']))
                : null;
        }

        if ($existing->status !== ReversalStatus::Open) {
            // Already decided, and `RecordPaymentReversal` treats a decided
            // reversal as terminal — so the second run over the same dispute
            // changes nothing, here or there.
            return null;
        }

        return $this->closes($existing, $dispute, $closesAs);
    }

    /**
     * An `Open` row and the decision Stripe has since made about it (#249).
     *
     * **The moment is now, never the dispute's `created`.** This shape
     * carries no closing timestamp, and `RecordPaymentReversal` writes the
     * moment it is handed to `finalized_at`, which is what `repay_due_on`
     * counts from. Dating a dispute lost today back to the week it was opened
     * would put the payer's deadline in the past and let
     * `commerce:cancel-unpaid-reversals` cancel the order on its next run
     * with no days at all — the trap
     * `StripeWebhookController::recordDispute()` avoids by taking the
     * event's timestamp rather than the dispute's.
     *
     * The row's own values travel rather than the API's: the amount, the
     * currency, the reason and the fee were decided when the row was
     * written, and `RecordPaymentReversal::transition()` reads none of them
     * anyway. Sending the row's own back is what keeps that true if it ever
     * does.
     *
     * @param  array{id: string, status: string, amount: int, currency: string, reason: ?string, fee: ?int, created: ?int}  $dispute
     * @return array{kind: ReversalKind, status: ReversalStatus, amount: string, currency: string, providerReference: string, reasonCode: ?string, feeAmount: ?string, occurredAt: Carbon}
     */
    private function closes(PaymentReversal $existing, array $dispute, ReversalStatus $closesAs): array
    {
        return [
            'kind' => $existing->kind,
            'status' => $closesAs,
            'amount' => (string) $existing->amount,
            'currency' => $existing->currency,
            'providerReference' => $dispute['id'],
            'reasonCode' => $existing->reason_code,
            'feeAmount' => $existing->fee_amount === null ? null : (string) $existing->fee_amount,
            'occurredAt' => Carbon::now(),
        ];
    }

    /**
     * One dispute as the API reports it, in the shape
     * `RecordPaymentReversal::handle()` takes. Null for a currency
     * `MinorUnits` refuses, which is the same drop the webhook makes.
     *
     * @param  array{id: string, status: string, amount: int, currency: string, reason: ?string, fee: ?int, created: ?int}  $dispute
     * @return array{kind: ReversalKind, status: ReversalStatus, amount: string, currency: string, providerReference: string, reasonCode: ?string, feeAmount: ?string, occurredAt: Carbon}|null
     */
    private function mapped(array $dispute, Payment $payment, ReversalStatus $status, Carbon $occurredAt): ?array
    {
        $currency = $this->currency($dispute['currency'], $payment);
        $amount = $this->toDecimal($dispute['amount'], $currency);

        if ($amount === null) {
            return null;
        }

        return [
            'kind' => ReversalKind::Dispute,
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'providerReference' => $dispute['id'],
            'reasonCode' => $dispute['reason'],
            'feeAmount' => $dispute['fee'] === null
                ? null
                : $this->toDecimal(abs($dispute['fee']), $currency),
            'occurredAt' => $occurredAt,
        ];
    }

    /**
     * The same `external_refund` `charge.refunded` records: money the
     * operator sent back somewhere other than through `RefundOrder`. A
     * refund Konventix itself made carries the reference `RefundOrder`
     * stored (#247), so `RecordPaymentReversal` converges on that row
     * instead of undoing the sale a second time.
     *
     * @param  array{id: string, amount: int, currency: string, reason: ?string, charge: ?string, created: ?int}  $refund
     * @return array{kind: ReversalKind, status: ReversalStatus, amount: string, currency: string, providerReference: string, reasonCode: ?string, feeAmount: ?string, occurredAt: Carbon}|null
     */
    private function refund(array $refund, Payment $payment): ?array
    {
        // The documented fallback of `StripeWebhookController::chargeRefunded()`:
        // an unexpanded `charge.refunds` leaves that path with the *charge*
        // id as its only unique handle, and this list names the same money by
        // its refund id. Recognising the charge's row here is what stops one
        // dashboard refund appearing twice on the order and being counted as
        // a second lost notification (fix round 1, review finding 3).
        if ($refund['charge'] !== null
            && PaymentReversal::forProviderReference($payment->provider, $refund['charge']) !== null) {
            return null;
        }

        $currency = $this->currency($refund['currency'], $payment);
        $amount = $this->toDecimal($refund['amount'], $currency);

        if ($amount === null) {
            return null;
        }

        return [
            'kind' => ReversalKind::ExternalRefund,
            'status' => ReversalStatus::Final,
            'amount' => $amount,
            'currency' => $currency,
            'providerReference' => $refund['id'],
            'reasonCode' => $refund['reason'],
            'feeAmount' => null,
            'occurredAt' => $this->occurredAt($refund['created']),
        ];
    }

    private function currency(string $currency, Payment $payment): string
    {
        return $currency === '' ? $payment->currency : strtoupper($currency);
    }

    /**
     * `MinorUnits` refuses a currency without minor units rather than
     * inventing cents; a reversal we cannot express is acknowledged in the
     * log and dropped, exactly as the webhook drops it.
     */
    private function toDecimal(int $minorUnits, string $currency): ?string
    {
        try {
            return MinorUnits::toDecimal($minorUnits, $currency);
        } catch (InvalidArgumentException $e) {
            Log::warning('Stripe reversal in a currency without minor units; not recorded (#235).', [
                'currency' => $currency,
                'minor_units' => $minorUnits,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function occurredAt(?int $timestamp): Carbon
    {
        return $timestamp === null ? Carbon::now() : Carbon::createFromTimestampUTC($timestamp);
    }
}

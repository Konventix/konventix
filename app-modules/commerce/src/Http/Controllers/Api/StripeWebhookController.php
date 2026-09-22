<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Konventix\Commerce\Actions\RecordPaymentFailure;
use Konventix\Commerce\Actions\RecordPaymentReversal;
use Konventix\Commerce\Actions\RecordPaymentSuccess;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\MinorUnits;
use Konventix\Commerce\Settlement\Stripe\StripeCredentials;
use Konventix\Commerce\Settlement\Stripe\StripeReversalCatchUp;
use Konventix\Commerce\Settlement\Stripe\StripeWebhookEvents;
use Stripe\Event as StripeEvent;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Throwable;
use UnexpectedValueException;

/**
 * Stripe's notification endpoint — the only place a Stripe payment becomes
 * settlement truth (ADR-0039). Signature-verified before a byte of the
 * payload is trusted, against the secret `StripeCredentials` resolves —
 * the operator's console-entered row first, the environment otherwise
 * (ADR-0058 point 2), so an operator who rotates the pair in the console
 * does not silently keep verifying against a stale environment value.
 *
 * One ordinary endpoint on this instance's own Stripe account, *not* a
 * Connect endpoint: every Checkout Session is created on that account
 * (ADR-0058 point 5), so nothing needs "Listen to events on connected
 * accounts". The Checkout Session id correlates each event to our
 * `Payment` row.
 *
 * Fulfills on both `completed` and `async_payment_succeeded`, gated on
 * `payment_status`: with delayed-notification methods (SEPA, bank transfer)
 * the completed event arrives while the session is still unpaid, so acting
 * on it alone would settle orders whose payment later fails.
 *
 * Reversals arrive on the same endpoint (ADR-0057 point 7):
 * `charge.dispute.created`, `charge.dispute.closed` and `charge.refunded`,
 * correlated through `provider_transaction_id` — the payment intent
 * recorded at settlement, which the dispute and the charge both name.
 * `charge.dispute.funds_withdrawn` and `funds_reinstated` are
 * informational and deliberately ignored: the dispute's own lifecycle
 * already says what happened to the money.
 *
 * Stripe does not order those events against the settlement, though, and a
 * reversal that arrives before it can be correlated would be acknowledged
 * and lost (#235) — so `settled()` asks Stripe once, the moment the intent
 * is known, whether it already holds a dispute or a refund against it. See
 * `catchUpOnReversals()`.
 *
 * Unknown sessions and event types return 200 on purpose: a non-2xx makes
 * Stripe retry for days, and "not ours" (another environment against the
 * same account) is not an error that retrying fixes.
 */
class StripeWebhookController
{
    public function __invoke(
        Request $request,
        StripeCredentials $credentials,
        RecordPaymentSuccess $recordSuccess,
        RecordPaymentFailure $recordFailure,
        RecordPaymentReversal $recordReversal,
    ): JsonResponse {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                // No signing secret at all verifies nothing: an empty
                // secret makes every signature invalid, which is the safe
                // direction — a 400 Stripe retries, not a settled order.
                $credentials->webhookSecret() ?? '',
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response()->json(['error' => 'invalid signature'], 400);
        }

        // `StripeWebhookEvents::ALL` is the same list the operator console
        // tells an operator to subscribe the endpoint to, and the tunnel
        // script forwards (#161) — the arms below are that list, so a new
        // event cannot be handled here without becoming visible there.
        return match ($event->type) {
            StripeWebhookEvents::CheckoutSessionCompleted,
            StripeWebhookEvents::CheckoutSessionAsyncPaymentSucceeded => $this->settled($event, $recordSuccess, $recordReversal),
            StripeWebhookEvents::CheckoutSessionAsyncPaymentFailed => $this->failed($event, $recordFailure, 'async_payment_failed'),
            StripeWebhookEvents::CheckoutSessionExpired => $this->failed($event, $recordFailure, 'checkout_expired', expired: true),
            StripeWebhookEvents::ChargeDisputeCreated => $this->disputeCreated($event, $recordReversal),
            StripeWebhookEvents::ChargeDisputeClosed => $this->disputeClosed($event, $recordReversal),
            StripeWebhookEvents::ChargeRefunded => $this->chargeRefunded($event, $recordReversal),
            default => response()->json(['received' => true]),
        };
    }

    private function settled(
        StripeEvent $event,
        RecordPaymentSuccess $recordSuccess,
        RecordPaymentReversal $recordReversal,
    ): JsonResponse {
        $session = $event->data->object;
        $payment = $this->paymentFor($session);

        if ($payment === null) {
            return response()->json(['received' => true]);
        }

        // The money identifier behind the session, needed for refunds —
        // recorded even while unpaid, so the async success only flips state.
        if (is_string($session->payment_intent ?? null)) {
            $payment->forceFill(['provider_transaction_id' => $session->payment_intent])->save();
        }

        if (($session->payment_status ?? 'unpaid') !== 'unpaid') {
            $recordSuccess->handle($payment);

            // Only now — with the intent stored and the payment settled —
            // can a reversal that arrived first be correlated at all (#235).
            $this->catchUpOnReversals($payment, $recordReversal);
        }

        return response()->json(['received' => true]);
    }

    /**
     * Asks Stripe once, at settlement, whether it already holds a dispute or
     * a refund against this payment intent (#235).
     *
     * The race this closes is real and reproducible: with the dispute test
     * card Stripe sends `charge.dispute.created` a second *before*
     * `checkout.session.completed`, when the payment carries no
     * `provider_transaction_id` and nothing can correlate the dispute. That
     * event is acknowledged with 200 — the only safe answer — and never
     * repeated, so without this question the reversal is lost and the
     * receivable never revives, while Stripe has withdrawn the money
     * (walkthrough #69 run C, 2026-09-08).
     *
     * **A failed question never costs a settlement.** The money arrived and
     * the order is paid before this runs; a Stripe outage, a revoked key or
     * a deployment with no credentials at all must leave that standing, not
     * turn into a 500 that makes Stripe retry a settlement it already
     * delivered. The daily reconciliation of #71 asks the same question
     * again, which is what makes swallowing this one safe. Only the *read*
     * is swallowed (review finding 7): a throw out of `RecordPaymentReversal`
     * — a listener, a deadlock — still reaches the endpoint as a 500 and is
     * retried by Stripe, which is the right answer for a write we could not
     * complete.
     *
     * Returns whether a **refund** was recorded — not whether Stripe
     * answered at all (fix round 2, review NEW-4). `chargeRefunded()` reads
     * it to decide whether it still needs its own charge-id fallback, and a
     * payment carrying an open dispute must not answer that question for the
     * refund the event was about.
     */
    private function catchUpOnReversals(Payment $payment, RecordPaymentReversal $recordReversal): bool
    {
        try {
            $reversals = app(StripeReversalCatchUp::class)->reversalsFor($payment);
        } catch (Throwable $e) {
            Log::warning('Stripe could not be asked about reversals; the reconciliation sweep asks again (#235).', [
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'provider_transaction_id' => $payment->provider_transaction_id,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }

        $refundsRecorded = 0;

        foreach ($reversals as $reversal) {
            if ($reversal['kind'] === ReversalKind::ExternalRefund) {
                $refundsRecorded++;
            }

            $recordReversal->handle(
                payment: $payment,
                kind: $reversal['kind'],
                status: $reversal['status'],
                amount: $reversal['amount'],
                currency: $reversal['currency'],
                providerReference: $reversal['providerReference'],
                reasonCode: $reversal['reasonCode'],
                feeAmount: $reversal['feeAmount'],
                occurredAt: $reversal['occurredAt'],
            );

            // The next reversal decides against the row this one just wrote:
            // a refund after a dispute reads the payment's new status.
            $payment->refresh();
        }

        // Whether a *refund* was recorded, which is the only question
        // `chargeRefunded()` is asking (fix round 2, review NEW-4). Counting
        // the whole list meant a payment carrying an open dispute answered
        // "yes" while the refund the event was about had not been listed
        // yet, so the charge-id fallback was never reached and the refund
        // waited for the daily sweep.
        return $refundsRecorded > 0;
    }

    private function failed(
        StripeEvent $event,
        RecordPaymentFailure $recordFailure,
        string $reasonCode,
        bool $expired = false,
    ): JsonResponse {
        $payment = $this->paymentFor($event->data->object);

        if ($payment !== null) {
            $recordFailure->handle($payment, $reasonCode, expired: $expired);
        }

        return response()->json(['received' => true]);
    }

    /**
     * A dispute that is born `lost` is Stripe's shape for a SEPA debit
     * return: no evidence can be filed, so it closes as it opens and is a
     * `return`, final at once (ADR-0057 point 1). Every other status opens
     * a `dispute` the provider decides later.
     */
    private function disputeCreated(StripeEvent $event, RecordPaymentReversal $recordReversal): JsonResponse
    {
        $lost = ($event->data->object->status ?? null) === 'lost';

        return $this->recordDispute(
            $event,
            $recordReversal,
            $lost ? ReversalKind::Return : ReversalKind::Dispute,
            $lost ? ReversalStatus::Final : ReversalStatus::Open,
        );
    }

    /**
     * `lost` finalises the dispute opened above; `won` and `warning_closed`
     * withdraw it. A status that closes nothing is acknowledged without a
     * record.
     *
     * The map is `StripeReversalCatchUp::CLOSING_STATUSES` rather than one
     * written here (#249): the reconciliation sweep has to close an open
     * reversal exactly as this event would, and two maps of the same money
     * would drift silently. `warning_closed` reaching a record at all is that
     * change — it used to be acknowledged and dropped, which left the `Open`
     * row `charge.dispute.created` had written standing for ever, the same
     * defect #249 filed against the sweep.
     */
    private function disputeClosed(StripeEvent $event, RecordPaymentReversal $recordReversal): JsonResponse
    {
        $reported = $event->data->object->status ?? null;
        $status = is_string($reported)
            ? StripeReversalCatchUp::CLOSING_STATUSES[$reported] ?? null
            : null;

        return $status === null
            ? response()->json(['received' => true])
            : $this->recordDispute($event, $recordReversal, ReversalKind::Dispute, $status);
    }

    /**
     * The moment is the **event's** timestamp, never the dispute's own
     * `created`. A card dispute stays open for weeks; on
     * `charge.dispute.closed` the object still carries the day it was born,
     * and taking that as the moment it was lost would date
     * `repay_due_on` into the past — the deadline sweep of ADR-0057 point 4
     * would then cancel the order on its first run and the payer would get
     * no days at all.
     */
    private function recordDispute(
        StripeEvent $event,
        RecordPaymentReversal $recordReversal,
        ReversalKind $kind,
        ReversalStatus $status,
    ): JsonResponse {
        $dispute = $event->data->object;
        $payment = $this->paymentForTransaction($dispute);
        $reference = $dispute->id ?? null;

        if ($payment === null || ! is_string($reference)) {
            return response()->json(['received' => true]);
        }

        $currency = strtoupper((string) ($dispute->currency ?? $payment->currency));

        $amount = $this->toDecimal((int) ($dispute->amount ?? 0), $currency);

        if ($amount === null) {
            return response()->json(['received' => true]);
        }

        $recordReversal->handle(
            payment: $payment,
            kind: $kind,
            status: $status,
            amount: $amount,
            currency: $currency,
            providerReference: $reference,
            reasonCode: is_string($dispute->reason ?? null) ? $dispute->reason : null,
            feeAmount: $this->disputeFee($dispute, $currency),
            occurredAt: $this->occurredAt($event->created),
        );

        return response()->json(['received' => true]);
    }

    /**
     * A refund we made ourselves is already recorded, and this arm is for
     * the one the operator made in Stripe's own dashboard instead (ADR-0057
     * point 1).
     *
     * The order still being `paid` is *usually* that condition, and the
     * docblock here used to claim it always was (blocker 1). It is not: the
     * provider call comes first on purpose (#236), so between it and
     * `RecordRefund`'s commit our own refund is a Stripe fact with no
     * Konventix record and an order that still reads `paid`.
     *
     * **What closes that window is the order lock**, and only that (review
     * NEW-2). `RecordRefund` takes it before it writes anything and holds it
     * until the credit note, the status and the reversal row commit
     * together, so this handler either sees none of them — and is then the
     * one that does the work, converging on the reversal row's unique key —
     * or sees all of them and returns on the status guard below. A guard
     * that looked for the `refund` row while the order still read `paid` sat
     * here for a wave and could not fire: under READ COMMITTED the row and
     * the status become visible in the same instant.
     */
    private function chargeRefunded(StripeEvent $event, RecordPaymentReversal $recordReversal): JsonResponse
    {
        $charge = $event->data->object;
        $payment = $this->paymentForTransaction($charge);

        if ($payment === null) {
            return response()->json(['received' => true]);
        }

        // Escapes BrandScope explicitly (ADR-0010): webhooks arrive on the
        // stateless api surface, where no brand is resolved from the host.
        $order = Order::withoutBrandScope()->find($payment->order_id);

        if ($order === null || $order->status !== OrderStatus::Paid) {
            return response()->json(['received' => true]);
        }

        $refund = $charge->refunds->data[0] ?? null;
        $reference = is_string($refund->id ?? null) ? $refund->id : null;

        if ($reference === null) {
            // `charge.refunds` has not been expanded by default since API
            // version 2022-11-15, so in production this event usually names
            // no `re_…` at all — and the catch-up of #235 lists the same
            // money *by* its `re_…`. Two paths, two keys, two rows for one
            // refund (fix round 1, review finding 3). Asking the seam for
            // the refund list is what makes them agree, and it is the same
            // question `settled()` and the reconciliation ask.
            if ($this->catchUpOnReversals($payment, $recordReversal)) {
                return response()->json(['received' => true]);
            }

            // Reached when Stripe could not be asked at all, and when it
            // answered without listing this refund yet (an open dispute is
            // not an answer about a refund). The charge's own id still
            // identifies this event uniquely, so the reversal is recorded
            // rather than lost. `StripeReversalCatchUp` knows about
            // this fallback and skips a refund whose charge already carries a
            // row, so the later sweep does not add a second one.
            $reference = $charge->id ?? null;
        }

        if (! is_string($reference)) {
            return response()->json(['received' => true]);
        }

        $currency = strtoupper((string) ($charge->currency ?? $payment->currency));

        $amount = $this->toDecimal((int) ($charge->amount_refunded ?? 0), $currency);

        if ($amount === null) {
            return response()->json(['received' => true]);
        }

        $recordReversal->handle(
            payment: $payment,
            kind: ReversalKind::ExternalRefund,
            status: ReversalStatus::Final,
            amount: $amount,
            currency: $currency,
            providerReference: $reference,
            reasonCode: is_string($refund->reason ?? null) ? $refund->reason : null,
            feeAmount: null,
            // The refund's own moment, not the charge's: the charge may be
            // months old, and the deadline and the payer mail both read
            // this.
            occurredAt: $this->occurredAt($refund->created ?? $event->created),
        );

        return response()->json(['received' => true]);
    }

    /**
     * The chargeback fee as Stripe reports it: the balance transaction that
     * withdrew the funds carries it. The organizer's own cost (ADR-0007),
     * recorded because the operator has to see it, never invoiced onward.
     */
    private function disputeFee(object $dispute, string $currency): ?string
    {
        $fee = $dispute->balance_transactions[0]->fee ?? null;

        return is_int($fee) || is_float($fee)
            ? MinorUnits::toDecimal(abs((int) $fee), $currency)
            : null;
    }

    /**
     * `MinorUnits` refuses a zero-decimal currency rather than inventing
     * cents, and this endpoint answers 200 to everything it cannot act on:
     * a 500 here would make Stripe retry the same unreadable event for
     * days. Null means "acknowledged, nothing recorded".
     */
    private function toDecimal(int $minorUnits, string $currency): ?string
    {
        try {
            return MinorUnits::toDecimal($minorUnits, $currency);
        } catch (InvalidArgumentException $e) {
            Log::warning('Stripe reversal in a currency without minor units; acknowledged without a record.', [
                'currency' => $currency,
                'minor_units' => $minorUnits,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function occurredAt(mixed $timestamp): Carbon
    {
        return is_int($timestamp) ? Carbon::createFromTimestampUTC($timestamp) : Carbon::now();
    }

    private function paymentFor(object $session): ?Payment
    {
        $sessionId = $session->id ?? null;

        return is_string($sessionId)
            ? Payment::forProviderPayment(SettlementAccount::PROVIDER_STRIPE, $sessionId)
            : null;
    }

    /**
     * Both the dispute and the charge name the payment intent we stored at
     * settlement. The charge id is tried second for an account that
     * predates that column being filled.
     */
    private function paymentForTransaction(object $object): ?Payment
    {
        foreach ([$object->payment_intent ?? null, $object->charge ?? null] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $payment = Payment::forProviderTransaction(SettlementAccount::PROVIDER_STRIPE, $candidate);

            if ($payment !== null) {
                return $payment;
            }
        }

        return null;
    }
}

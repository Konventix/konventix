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
use Stripe\Event as StripeEvent;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Stripe's notification endpoint — the only place a Stripe payment becomes
 * settlement truth (ADR-0039). Signature-verified against the platform
 * signing secret before a byte of the payload is trusted; Connect delivers
 * connected-account events to this one platform endpoint, and the Checkout
 * Session id correlates them to our `Payment` row.
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
 * Unknown sessions and event types return 200 on purpose: a non-2xx makes
 * Stripe retry for days, and "not ours" (another environment against the
 * same account) is not an error that retrying fixes.
 */
class StripeWebhookController
{
    public function __invoke(
        Request $request,
        RecordPaymentSuccess $recordSuccess,
        RecordPaymentFailure $recordFailure,
        RecordPaymentReversal $recordReversal,
    ): JsonResponse {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                config()->string('services.stripe.webhook_secret'),
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response()->json(['error' => 'invalid signature'], 400);
        }

        return match ($event->type) {
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => $this->settled($event, $recordSuccess),
            'checkout.session.async_payment_failed' => $this->failed($event, $recordFailure, 'async_payment_failed'),
            'checkout.session.expired' => $this->failed($event, $recordFailure, 'checkout_expired', expired: true),
            'charge.dispute.created' => $this->disputeCreated($event, $recordReversal),
            'charge.dispute.closed' => $this->disputeClosed($event, $recordReversal),
            'charge.refunded' => $this->chargeRefunded($event, $recordReversal),
            default => response()->json(['received' => true]),
        };
    }

    private function settled(StripeEvent $event, RecordPaymentSuccess $recordSuccess): JsonResponse
    {
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
        }

        return response()->json(['received' => true]);
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
     * `lost` finalises the dispute opened above, `won` withdraws it. Every
     * other closing status (`warning_closed`) decides nothing about the
     * money and is acknowledged without a record.
     */
    private function disputeClosed(StripeEvent $event, RecordPaymentReversal $recordReversal): JsonResponse
    {
        $status = match ($event->data->object->status ?? null) {
            'lost' => ReversalStatus::Final,
            'won' => ReversalStatus::Withdrawn,
            default => null,
        };

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
     * A refund we made ourselves is already recorded — `RefundOrder` set
     * the order to `refunded` inside the same transaction as the provider
     * call. Only a refund with no Konventix refund behind it is the
     * operator acting in the provider's dashboard (ADR-0057 point 1), and
     * the order still being `paid` is exactly that condition.
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
        // A charge whose refund list was not expanded still identifies the
        // event uniquely by its own id, which is what the row needs to
        // converge on a replay.
        $reference = is_string($refund->id ?? null) ? $refund->id : ($charge->id ?? null);

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

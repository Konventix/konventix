<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Konventix\Commerce\Actions\RecordPaymentFailure;
use Konventix\Commerce\Actions\RecordPaymentSuccess;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
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

    private function paymentFor(object $session): ?Payment
    {
        $sessionId = $session->id ?? null;

        return is_string($sessionId)
            ? Payment::forProviderPayment(SettlementAccount::PROVIDER_STRIPE, $sessionId)
            : null;
    }
}

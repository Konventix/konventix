<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Stripe;

/**
 * The seam between the Stripe adapter and the Stripe SDK (ADR-0039): the
 * adapter's decisions — what to charge, how to react — are testable against
 * a fake of this interface, while `SdkStripeApi` is a thin pass-through
 * that owns nothing worth unit-testing.
 *
 * Every call is made on **this instance's own Stripe account** (ADR-0058):
 * the operator running the instance is the merchant of record, the key
 * `StripeCredentials` resolves is that account's key, and no call carries
 * an account id or a `Stripe-Account` header. Which brand a payment belongs
 * to is metadata on the session and the intent, not an account of its own.
 */
interface StripeApi
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function createCheckoutSession(array $params): StripeCheckoutSession;

    /**
     * @param  array<string, mixed>  $params
     */
    public function createRefund(array $params): void;

    /**
     * The current state of a Checkout Session, read back from Stripe —
     * the fetch-back half Stripe otherwise does not need, because its
     * webhook is signed and complete (ADR-0039). The scheduled
     * reconciliation of #71 asks it anyway: a notification lost between
     * Stripe and this deployment leaves a `pending` payment for money that
     * moved, and only the provider can say so.
     *
     * `status` is the session's own lifecycle (`open`, `complete`,
     * `expired`); `payment_status` is what settles the payment
     * (`unpaid`, `paid`, `no_payment_required`), the same field
     * `StripeWebhookController::settled()` gates on, because with
     * delayed-notification methods a session completes while still unpaid.
     * `payment_intent` is the money identifier a later refund needs, and
     * `payment_intent_status` is what the session alone cannot say: with a
     * delayed-notification method the session reaches `complete` while
     * still `unpaid`, and a *refused* attempt never turns the session
     * `expired` — only the intent falling back to `requires_payment_method`
     * (or `canceled`) reports it. That is the same fact
     * `checkout.session.async_payment_failed` carries, which is why the
     * reconciliation has to read it. Null where the session has no intent.
     *
     * @return array{status: string, payment_status: string, payment_intent: ?string, payment_intent_status: ?string}
     */
    public function fetchCheckoutSession(string $sessionId): array;
}

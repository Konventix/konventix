<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Stripe;

/**
 * The Stripe events this deployment settles on (ADR-0039: webhooks are the
 * only source of settlement truth). One source of truth for three readers
 * that would otherwise drift apart: `StripeWebhookController`'s dispatch,
 * the operator console's setup instructions (#161 — an operator creating
 * the endpoint in Stripe's dashboard has to pick exactly these), and
 * `contrib/stripe-listen.sh`'s `--events` list for local development.
 *
 * A constant class rather than an enum: these are Stripe's strings, not a
 * domain vocabulary of ours, and the controller matches on them.
 *
 * Checkout Sessions are created on the connected account (ADR-0039 point 5,
 * the `Stripe-Account` header), so every one of these fires on the
 * connected account — a platform endpoint only receives them when it was
 * created with "Listen to events on connected accounts".
 */
final class StripeWebhookEvents
{
    public const string CheckoutSessionCompleted = 'checkout.session.completed';

    public const string CheckoutSessionAsyncPaymentSucceeded = 'checkout.session.async_payment_succeeded';

    public const string CheckoutSessionAsyncPaymentFailed = 'checkout.session.async_payment_failed';

    public const string CheckoutSessionExpired = 'checkout.session.expired';

    public const string ChargeDisputeCreated = 'charge.dispute.created';

    public const string ChargeDisputeClosed = 'charge.dispute.closed';

    public const string ChargeRefunded = 'charge.refunded';

    /**
     * Ordered as an operator reads them: the checkout lifecycle first, then
     * the reversals of ADR-0057 point 7.
     *
     * @var list<string>
     */
    public const array ALL = [
        self::CheckoutSessionCompleted,
        self::CheckoutSessionAsyncPaymentSucceeded,
        self::CheckoutSessionAsyncPaymentFailed,
        self::CheckoutSessionExpired,
        self::ChargeDisputeCreated,
        self::ChargeDisputeClosed,
        self::ChargeRefunded,
    ];
}

<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Stripe;

/**
 * The two facts the adapter needs back from a created Checkout Session:
 * Stripe's id (stored as the payment's `external_id`, the webhook
 * correlation key) and the hosted payment page URL.
 */
final readonly class StripeCheckoutSession
{
    public function __construct(
        public string $id,
        public string $url,
    ) {}
}

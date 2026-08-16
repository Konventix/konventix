<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Stripe;

/**
 * The seam between the Stripe adapter and the Stripe SDK (ADR-0039): the
 * adapter's decisions — what to charge, on whose account, how to react —
 * are testable against a fake of this interface, while `SdkStripeApi` is a
 * thin pass-through that owns nothing worth unit-testing.
 *
 * Every call carries `$stripeAccountId`: charges are created *on* the
 * connected account (direct charges — the organizer is merchant of record,
 * ADR-0007/ADR-0039), authenticated by the platform key plus the
 * `Stripe-Account` header.
 */
interface StripeApi
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function createCheckoutSession(array $params, string $stripeAccountId): StripeCheckoutSession;

    /**
     * @param  array<string, mixed>  $params
     */
    public function createRefund(array $params, string $stripeAccountId): void;
}

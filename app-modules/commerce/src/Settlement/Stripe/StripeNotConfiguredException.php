<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Stripe;

use RuntimeException;

/**
 * This instance has no Stripe secret key — in neither of the two places one
 * may live (ADR-0058 point 2: the operator's console-entered settings row
 * wins, otherwise the environment).
 *
 * It exists as its own class rather than a bare `RuntimeException` so the
 * operator console can catch exactly this one and repeat its message
 * instead of answering 500 (GitLab #194): the message is the whole point,
 * so it names what is missing *and* both places it can be put — an operator
 * reading it should not need to find this file.
 */
final class StripeNotConfiguredException extends RuntimeException
{
    public static function missingPlatformKey(): self
    {
        return new self(
            'Stripe is not configured on this deployment: no secret key for this instance\'s own Stripe '
            .'account is set, so nothing can be charged, refunded or reconciled (ADR-0058). '
            .'There are two places to put it, and the first one wins: enter the Secret Key and the '
            .'Webhook Signing Secret in the operator console under Settlement accounts → Stripe account, '
            .'where they are stored encrypted; or set STRIPE_SECRET — and STRIPE_WEBHOOK_SECRET beside it, '
            .'which signs the webhooks that are the only source of settlement truth — in the environment '
            .'this deployment reads; the app sees them as `services.stripe.secret` and '
            .'`services.stripe.webhook_secret`. On a chart deployment both keys are the `secrets.payments` '
            .'group of konventix-helm\'s values.yaml. '
            .'A cached config does not follow a changed environment: run `artisan config:clear` after setting them.',
        );
    }
}

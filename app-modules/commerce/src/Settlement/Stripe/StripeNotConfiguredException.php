<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Stripe;

use RuntimeException;

/**
 * This deployment has no Stripe platform key (ADR-0039 point 4: the
 * platform secret and the webhook signing secret belong to the platform,
 * not to a brand, and live in `config/services.php` from the environment).
 *
 * It exists as its own class rather than a bare `RuntimeException` so the
 * operator console can catch exactly this one and repeat its message
 * instead of answering 500 (GitLab #194): the message is the whole point,
 * so it names what is missing *and* where to put it — an operator reading
 * it should not need to find this file.
 */
final class StripeNotConfiguredException extends RuntimeException
{
    public static function missingPlatformKey(): self
    {
        return new self(
            'Stripe is not configured on this deployment: the platform key STRIPE_SECRET is empty, '
            .'so no Stripe account can be created, onboarded or charged (ADR-0039). '
            .'Set STRIPE_SECRET — and STRIPE_WEBHOOK_SECRET beside it, which signs the webhooks that are '
            .'the only source of settlement truth — in the environment this deployment reads; the app sees '
            .'them as `services.stripe.secret` and `services.stripe.webhook_secret`. On a chart deployment '
            .'both keys are the `secrets.payments` group of konventix-helm\'s values.yaml. '
            .'A cached config does not follow a changed environment: run `artisan config:clear` after setting them.',
        );
    }
}

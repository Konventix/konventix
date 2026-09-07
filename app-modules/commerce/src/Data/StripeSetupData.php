<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Settlement\Stripe\StripeWebhookEvents;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What an operator needs in front of them before they connect a brand's
 * Stripe account (#161, ADR-0039 point 4/5). The console asks for it at the
 * moment it is missing — the alternative was a bare 500 from an empty
 * `STRIPE_SECRET` and no hint where the signing secret comes from.
 *
 * These are the *platform-level* credentials for the Connect onboarding:
 * one pair per deployment, from the environment, never a brand row. #161
 * will later add a second mode where a brand brings its own keys — that is
 * a different pair, entered in the dialog, and does not change anything
 * here.
 */
#[TypeScript]
final class StripeSetupData extends Data
{
    /**
     * @param  list<string>  $events
     */
    public function __construct(
        public readonly string $webhookUrl,
        public readonly array $events,
        public readonly bool $platformConfigured,
        public readonly string $secretGroupHint,
    ) {}

    public static function ofConfig(): self
    {
        return new self(
            // `routes/api/` carries no domain constraint, so this resolves
            // against whatever host the console is being rendered on — the
            // operator admin host. Stripe reaches the endpoint on any host
            // of the instance; the one the operator is already looking at is
            // the one they can paste without a second thought.
            webhookUrl: route('api.commerce.webhooks.stripe'),
            events: StripeWebhookEvents::ALL,
            // Whether, never what: no secret value leaves the server for a
            // page prop, not even truncated.
            platformConfigured: self::filled('services.stripe.secret')
                && self::filled('services.stripe.webhook_secret'),
            secretGroupHint: 'STRIPE_SECRET and STRIPE_WEBHOOK_SECRET in this deployment\'s environment '
                .'(the `secrets.payments` group on a chart deployment). '
                .'A cached config does not follow a changed environment: run `artisan config:clear` after setting them.',
        );
    }

    private static function filled(string $key): bool
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '';
    }
}

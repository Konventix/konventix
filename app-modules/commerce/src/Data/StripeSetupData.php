<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Settlement\Stripe\StripeCredentials;
use Konventix\Commerce\Settlement\Stripe\StripeWebhookEvents;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the operator console shows about this instance's own Stripe account
 * (ADR-0058 point 2, #215): whether it can charge at all, where the key
 * came from, which mode it is in, and the endpoint and events an operator
 * has to create in the Stripe dashboard before any of it settles.
 *
 * "Can charge at all" is `configured` and means **both** keys, because a
 * checkout with no verifiable webhook would take money and never settle
 * (ADR-0039 point 2); `secretKeySet` and `webhookSecretSet` say which half is
 * missing when it is false.
 *
 * Whether, never what: no key value leaves the server for a page prop, not
 * even truncated — `mode` and `lastFour` are derived, and the last four
 * characters of a Stripe key identify it without revealing it.
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
        public readonly bool $configured,
        public readonly bool $secretKeySet,
        public readonly bool $webhookSecretSet,
        public readonly string $source,
        public readonly ?string $mode,
        public readonly ?string $lastFour,
        public readonly bool $publishableKeySet,
        public readonly bool $liveKeyOffProduction,
        public readonly string $secretGroupHint,
    ) {}

    public static function ofCredentials(StripeCredentials $credentials): self
    {
        return new self(
            // `routes/api/` carries no domain constraint, so this resolves
            // against whatever host the console is being rendered on — the
            // operator admin host. Stripe reaches the endpoint on any host
            // of the instance; the one the operator is already looking at is
            // the one they can paste without a second thought.
            webhookUrl: route('api.commerce.webhooks.stripe'),
            events: StripeWebhookEvents::ALL,
            // `configured` is the pair — whether this instance may start a
            // checkout at all (ADR-0039 point 2). The two halves travel beside
            // it so the card can name the one that is missing instead of
            // reporting "no key" when the key is there.
            configured: $credentials->configured(),
            secretKeySet: $credentials->secretKeyConfigured(),
            webhookSecretSet: $credentials->webhookSecretConfigured(),
            source: $credentials->source(),
            mode: $credentials->mode(),
            lastFour: $credentials->lastFour(),
            publishableKeySet: $credentials->publishableKeySet(),
            liveKeyOffProduction: $credentials->liveKeyOffProduction(),
            // One literal, not a concatenation: the catalog key is the whole
            // sentence (ADR-0068), and a split key is one no tool can find.
            secretGroupHint: __('Entered here they are stored encrypted and win over the environment; otherwise STRIPE_SECRET and STRIPE_WEBHOOK_SECRET in this deployment’s environment (the `secrets.payments` group on a chart deployment) are used. A cached config does not follow a changed environment: run `artisan config:clear` after setting them.'),
        );
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Stripe;

use RuntimeException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * The real `StripeApi`: a thin pass-through to the Stripe SDK, built on a
 * `StripeClient` instance (never the deprecated global key). Deliberately
 * free of logic; everything decidable lives in `StripeSettlementAdapter`,
 * which is tested against a fake of the interface instead of this class.
 *
 * The client carries this instance's own secret key
 * (`CommerceServiceProvider`'s binding, resolved by `StripeCredentials`),
 * and every call below is made on that account — no `stripe_account`
 * request option anywhere, because there is no connected account to act on
 * (ADR-0058).
 */
final class SdkStripeApi implements StripeApi
{
    public function __construct(
        private readonly StripeClient $client,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public function createCheckoutSession(array $params): StripeCheckoutSession
    {
        $session = $this->client->checkout->sessions->create($params);

        if ($session->url === null) {
            throw new RuntimeException(sprintf(
                'Stripe returned checkout session [%s] without a hosted payment URL.',
                $session->id,
            ));
        }

        return new StripeCheckoutSession(id: $session->id, url: $session->url);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function createRefund(array $params): void
    {
        $this->client->refunds->create($params);
    }

    /**
     * The intent is expanded rather than left as an id: a delayed
     * notification method (SEPA, bank transfer) completes the session while
     * it is still `unpaid`, and only the intent's own status distinguishes
     * "still processing" from "the attempt was refused" — the fact
     * `checkout.session.async_payment_failed` would have carried.
     *
     * @return array{status: string, payment_status: string, payment_intent: ?string, payment_intent_status: ?string}
     */
    public function fetchCheckoutSession(string $sessionId): array
    {
        $session = $this->client->checkout->sessions->retrieve(
            $sessionId,
            ['expand' => ['payment_intent']],
        );

        $intent = $session->payment_intent;

        return [
            // The SDK types `status` nullable (a session that has not been
            // categorised yet); `payment_status` is always one of `paid`,
            // `unpaid`, `no_payment_required`.
            'status' => $session->status ?? 'unknown',
            'payment_status' => $session->payment_status,
            // Expanded it is the object; a session Stripe declined to
            // expand still carries the plain id, which is what a refund
            // needs.
            'payment_intent' => $intent instanceof PaymentIntent ? $intent->id : (is_string($intent) ? $intent : null),
            'payment_intent_status' => $intent instanceof PaymentIntent ? $intent->status : null,
        ];
    }
}

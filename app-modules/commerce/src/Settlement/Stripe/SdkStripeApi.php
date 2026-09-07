<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Stripe;

use Illuminate\Support\Arr;
use RuntimeException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * The real `StripeApi`: a thin pass-through to the Stripe SDK, built on a
 * `StripeClient` instance (never the deprecated global key). Deliberately
 * free of logic; everything decidable lives in `StripeSettlementAdapter`,
 * which is tested against a fake of the interface instead of this class.
 */
final class SdkStripeApi implements StripeApi
{
    public function __construct(
        private readonly StripeClient $client,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public function createCheckoutSession(array $params, string $stripeAccountId): StripeCheckoutSession
    {
        $session = $this->client->checkout->sessions->create(
            $params,
            ['stripe_account' => $stripeAccountId],
        );

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
    public function createRefund(array $params, string $stripeAccountId): void
    {
        $this->client->refunds->create(
            $params,
            ['stripe_account' => $stripeAccountId],
        );
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
    public function fetchCheckoutSession(string $sessionId, string $stripeAccountId): array
    {
        $session = $this->client->checkout->sessions->retrieve(
            $sessionId,
            ['expand' => ['payment_intent']],
            ['stripe_account' => $stripeAccountId],
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

    public function createConnectedAccount(string $displayName, string $contactEmail, string $country): string
    {
        $account = $this->client->v2->core->accounts->create([
            'display_name' => $displayName,
            'contact_email' => $contactEmail,
            'identity' => ['country' => $country],
            'dashboard' => 'full',
            'defaults' => [
                'responsibilities' => [
                    'fees_collector' => 'stripe',
                    'losses_collector' => 'stripe',
                ],
            ],
            'configuration' => [
                'merchant' => [
                    'capabilities' => [
                        'card_payments' => ['requested' => true],
                    ],
                ],
            ],
            'include' => ['configuration.merchant'],
        ]);

        return $account->id;
    }

    public function createOnboardingLink(string $stripeAccountId, string $returnUrl, string $refreshUrl): string
    {
        $link = $this->client->v2->core->accountLinks->create([
            'account' => $stripeAccountId,
            'use_case' => [
                'type' => 'account_onboarding',
                'account_onboarding' => [
                    'configurations' => ['merchant'],
                    'return_url' => $returnUrl,
                    'refresh_url' => $refreshUrl,
                ],
            ],
        ]);

        return $link->url;
    }

    public function fetchAccount(string $stripeAccountId): array
    {
        $account = $this->client->v2->core->accounts->retrieve($stripeAccountId, [
            'include' => ['configuration.merchant'],
        ]);

        // `toArray()` recurses through the nested `StripeObject`s into
        // plain arrays, so a missing level (no merchant configuration yet)
        // reads as a missing key instead of a magic-property notice.
        $status = Arr::get($account->toArray(), 'configuration.merchant.capabilities.card_payments.status');

        return ['card_payments' => is_string($status) ? $status : 'unknown'];
    }
}

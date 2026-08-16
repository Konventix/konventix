<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Stripe;

use RuntimeException;
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
}

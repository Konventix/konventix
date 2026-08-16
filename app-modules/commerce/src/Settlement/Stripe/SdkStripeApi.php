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
}

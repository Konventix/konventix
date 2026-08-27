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

    /**
     * Creates the v2 connected account in the SaaS shape ADR-0039 pins:
     * full dashboard, merchant configuration with card payments, Stripe
     * collecting its own fees and carrying the losses — the organizer runs
     * their own business on their own account. Returns the account id.
     */
    public function createConnectedAccount(string $displayName, string $contactEmail, string $country): string;

    /**
     * A single-use Stripe-hosted onboarding URL for the merchant
     * configuration of `$stripeAccountId`.
     */
    public function createOnboardingLink(string $stripeAccountId, string $returnUrl, string $refreshUrl): string;

    /**
     * The current state of `$stripeAccountId`'s merchant configuration, as
     * Stripe itself reports it — the periodic refresh
     * `RefreshSettlementAccountCapabilities` turns into whether
     * `SettlementAccount::isOffered()` shows the account at checkout.
     *
     * @return array{card_payments: string} 'card_payments' is one of
     *                                      'active'|'inactive'|'pending', or 'unknown' when the account has no
     *                                      merchant configuration yet (onboarding not started/completed).
     */
    public function fetchAccount(string $stripeAccountId): array;
}

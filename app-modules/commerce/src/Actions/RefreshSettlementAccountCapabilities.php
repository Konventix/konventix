<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Stripe\StripeApi;

/**
 * Pulls the current capability state for one account and stamps it, so
 * `SettlementAccount::isOffered()` reflects what the provider itself
 * reports rather than what we assumed at connect time. Stripe is asked
 * directly (ADR-0039: onboarding completes on Stripe's own timeline, after
 * this platform stops hearing about it); every other provider is
 * considered offered once connected, which `isOffered()` already treats as
 * its default.
 *
 * Called from `SettlementAccountAdminController::stripeReturn()` right
 * after the account is marked connected, and daily from
 * `RefreshSettlementCapabilities` for every live Stripe account, so a
 * capability that activates later (Stripe's own review, not this return
 * trip) still shows up without an operator visiting the page again.
 */
final class RefreshSettlementAccountCapabilities
{
    public function __construct(private readonly StripeApi $stripe) {}

    public function handle(SettlementAccount $account): SettlementAccount
    {
        $capabilities = match ($account->provider) {
            SettlementAccount::PROVIDER_STRIPE => $account->provider_account_id === null
                ? ['card_payments' => 'unknown']
                : $this->stripe->fetchAccount($account->provider_account_id),
            default => ['connected' => $account->connected_at !== null],
        };

        $account->forceFill(['capabilities' => $capabilities, 'capabilities_checked_at' => now()])->save();

        return $account;
    }
}

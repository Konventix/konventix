<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Konventix\Commerce\Actions\ConnectSettlementAccount;
use Konventix\Commerce\Exceptions\BrandAlreadyHasLiveProviderException;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Mollie\MollieSettlementAdapter;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeCheckoutSession;
use Konventix\Commerce\Settlement\Stripe\StripeSettlementAdapter;

/**
 * ADR-0046: one live account per provider per brand, every one of them
 * offered to the payer; the slice-7 "single answer" rule is reversed here.
 *
 * Resolving `StripeSettlementAdapter` from the container wires a real
 * `StripeApi` unless one is faked (the platform key is unconfigured in
 * tests) — no call to the fake is exercised, only construction.
 */
final class MultiProviderSettlementTestFakeStripe implements StripeApi
{
    public function createCheckoutSession(array $params, string $stripeAccountId): StripeCheckoutSession
    {
        return new StripeCheckoutSession(id: 'cs_unused', url: 'https://stripe.test/unused');
    }

    public function createRefund(array $params, string $stripeAccountId): void {}

    public function createConnectedAccount(string $displayName, string $contactEmail, string $country): string
    {
        return 'acct_unused';
    }

    public function createOnboardingLink(string $stripeAccountId, string $returnUrl, string $refreshUrl): string
    {
        return 'https://stripe.test/unused';
    }
}

it('lets a brand keep one live account per provider', function (): void {
    $brand = $this->withBrand();

    $stripe = app(ConnectSettlementAccount::class)->handle($brand->getKey(), SettlementAccount::PROVIDER_STRIPE, providerAccountId: 'acct_1');
    $mollie = app(ConnectSettlementAccount::class)->handle($brand->getKey(), SettlementAccount::PROVIDER_MOLLIE, credentials: ['api_key' => 'test_x']);

    expect(SettlementAccount::liveAccountsFor($brand->getKey())->pluck('id')->all())->toBe([$stripe->id, $mollie->id]);
});

it('refuses a second live account of the same provider', function (): void {
    $brand = $this->withBrand();
    app(ConnectSettlementAccount::class)->handle($brand->getKey(), SettlementAccount::PROVIDER_MOLLIE, credentials: ['api_key' => 'a']);

    expect(fn () => app(ConnectSettlementAccount::class)->handle($brand->getKey(), SettlementAccount::PROVIDER_MOLLIE, credentials: ['api_key' => 'b']))
        ->toThrow(BrandAlreadyHasLiveProviderException::class);

    // And the database agrees even when the Action is bypassed.
    expect(fn () => SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_MOLLIE)->create())
        ->toThrow(QueryException::class);
});

it('offers a Stripe account only once card payments are active, and a key-based one once connected', function (): void {
    $this->withBrand();

    $pending = SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_STRIPE)->create(['capabilities' => ['card_payments' => 'pending']]);
    $active = SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_STRIPE)->disconnected()->create(['capabilities' => ['card_payments' => 'active']]);
    $mollie = SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_MOLLIE)->create();
    $onboarding = SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_UNZER)->create(['connected_at' => null]);

    expect($pending->isOffered())->toBeFalse()
        ->and($active->isOffered())->toBeFalse()   // disconnected
        ->and($mollie->isOffered())->toBeTrue()
        ->and($onboarding->isOffered())->toBeFalse();
});

it('labels an account for the checkout by the operator\'s words or the adapter\'s default', function (): void {
    $this->withBrand();

    $stripe = SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_STRIPE)->create();
    $mollie = SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_MOLLIE)->create(['checkout_label' => 'PayPal oder Sofort']);

    app()->instance(StripeApi::class, new MultiProviderSettlementTestFakeStripe);

    expect($stripe->checkoutLabel(app(StripeSettlementAdapter::class)))->toBe('Card, Apple Pay, Google Pay')
        ->and($mollie->checkoutLabel(app(MollieSettlementAdapter::class)))->toBe('PayPal oder Sofort');
});

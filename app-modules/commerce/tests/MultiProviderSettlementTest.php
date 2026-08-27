<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Konventix\Commerce\Actions\BookManualPayment;
use Konventix\Commerce\Actions\ConnectSettlementAccount;
use Konventix\Commerce\Actions\RecordPaymentSuccess;
use Konventix\Commerce\Actions\RefreshSettlementAccountCapabilities;
use Konventix\Commerce\Console\RefreshSettlementCapabilities;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\PaymentReceived;
use Konventix\Commerce\Exceptions\BrandAlreadyHasLiveProviderException;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Mollie\MollieSettlementAdapter;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeCheckoutSession;
use Konventix\Commerce\Settlement\Stripe\StripeSettlementAdapter;
use Konventix\Platform\Models\Brand;

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
    /** @var list<string> */
    public array $fetchedAccountIds = [];

    /**
     * @param  array{card_payments: string}  $capabilities  what `fetchAccount()` returns for every account.
     */
    public function __construct(
        private readonly array $capabilities = ['card_payments' => 'unknown'],
    ) {}

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

    public function fetchAccount(string $stripeAccountId): array
    {
        $this->fetchedAccountIds[] = $stripeAccountId;

        return $this->capabilities;
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

it('refreshes a Stripe account\'s capabilities from the platform API and offers it once active', function (): void {
    $this->withBrand();
    $account = SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_STRIPE)->create(['provider_account_id' => 'acct_42']);
    app()->instance(StripeApi::class, new MultiProviderSettlementTestFakeStripe(['card_payments' => 'active']));

    app(RefreshSettlementAccountCapabilities::class)->handle($account);

    expect($account->refresh()->capabilities)->toBe(['card_payments' => 'active'])
        ->and($account->capabilities_checked_at)->not->toBeNull()
        ->and($account->isOffered())->toBeTrue();
});

it('runs the refresh for every live Stripe account from the scheduled command', function (): void {
    $brandA = $this->withBrand();
    $stripeA = SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_STRIPE)->create(['provider_account_id' => 'acct_a']);

    $brandB = $this->withBrand(Brand::factory()->create());
    $stripeB = SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_STRIPE)->create(['provider_account_id' => 'acct_b']);

    $mollie = SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_MOLLIE)->create();

    $fake = new MultiProviderSettlementTestFakeStripe(['card_payments' => 'active']);
    app()->instance(StripeApi::class, $fake);

    $this->artisan(RefreshSettlementCapabilities::class)->assertSuccessful();

    expect($fake->fetchedAccountIds)->toContain('acct_a', 'acct_b')
        ->and($stripeA->refresh()->capabilities)->toBe(['card_payments' => 'active'])
        ->and($stripeA->capabilities_checked_at)->not->toBeNull()
        ->and($stripeB->refresh()->capabilities)->toBe(['card_payments' => 'active'])
        ->and($stripeB->capabilities_checked_at)->not->toBeNull()
        ->and($mollie->refresh()->capabilities)->toBeNull()
        ->and($mollie->capabilities_checked_at)->toBeNull();

    // Brand context of the running command is irrelevant — both brands' accounts were reached.
    expect($brandA->id)->not->toBe($brandB->id);
});

it('names the provider and the account on the settlement events', function (): void {
    $this->withBrand();

    $account = SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_STRIPE)->create();
    $order = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'total_gross' => '99.00',
        'currency' => 'EUR',
    ]);
    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'settlement_account_id' => $account->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'amount' => '99.00',
        'currency' => 'EUR',
    ]);

    Event::fake([OrderPaid::class, PaymentReceived::class]);

    app(RecordPaymentSuccess::class)->handle($payment);

    Event::assertDispatched(
        OrderPaid::class,
        fn (OrderPaid $event): bool => $event->settlementMethod === 'stripe'
            && $event->settlementAccountId === $account->id,
    );
    Event::assertDispatched(
        PaymentReceived::class,
        fn (PaymentReceived $event): bool => $event->settlementMethod === 'stripe'
            && $event->settlementAccountId === $account->id,
    );

    // A manual booking names the provider, but no account took the money.
    $manualOrder = Order::factory()->create();

    Event::fake([OrderPaid::class, PaymentReceived::class]);

    app(BookManualPayment::class)->handle($manualOrder);

    Event::assertDispatched(
        OrderPaid::class,
        fn (OrderPaid $event): bool => $event->settlementMethod === 'manual'
            && $event->settlementAccountId === null,
    );
    Event::assertDispatched(
        PaymentReceived::class,
        fn (PaymentReceived $event): bool => $event->settlementMethod === 'manual'
            && $event->settlementAccountId === null,
    );
});

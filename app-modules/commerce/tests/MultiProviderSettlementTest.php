<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
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
use Konventix\Commerce\Settlement\Mollie\MollieApi;
use Konventix\Commerce\Settlement\Mollie\MolliePaymentSnapshot;
use Konventix\Commerce\Settlement\Mollie\MollieSettlementAdapter;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeCheckoutSession;
use Konventix\Commerce\Settlement\Stripe\StripeSettlementAdapter;
use Konventix\Identity\Models\User;
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

/**
 * A fake of the `MollieApi` seam for the checkout-account-choice tests
 * below — no SDK call leaves the process.
 */
final class MultiProviderSettlementTestFakeMollieApi implements MollieApi
{
    /** @var list<array{params: array<string, mixed>, apiKey: string}> */
    public array $created = [];

    public function createPayment(array $params, string $apiKey): MolliePaymentSnapshot
    {
        $this->created[] = ['params' => $params, 'apiKey' => $apiKey];

        return new MolliePaymentSnapshot(
            id: 'tr_multi_'.count($this->created),
            status: 'open',
            checkoutUrl: 'https://www.mollie.test/checkout/select-method/multi',
        );
    }

    public function fetchPayment(string $paymentId, string $apiKey): MolliePaymentSnapshot
    {
        return new MolliePaymentSnapshot(id: $paymentId, status: 'open');
    }

    public function refundPayment(string $paymentId, array $params, string $apiKey): void {}
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

it('lists every offered hosted account on the checkout page with its label', function (): void {
    $this->withBrand();
    // Both `/pay/registration/...` routes carry `auth` since #2 — a
    // declaration at checkout needs a subject to record it against.
    $this->actingAs(User::factory()->create());
    $registrationId = (string) Str::ulid();

    Order::factory()->create([
        'status' => OrderStatus::Pending,
        'settles_type' => 'registration',
        'settles_id' => $registrationId,
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);

    app()->instance(StripeApi::class, new MultiProviderSettlementTestFakeStripe(['card_payments' => 'active']));

    // Ordered before Mollie so it lands first on the page (liveAccountsFor
    // orders by connected_at).
    SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_STRIPE)
        ->create(['capabilities' => ['card_payments' => 'active'], 'connected_at' => now()->subMinute()]);
    SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_MOLLIE)
        ->create(['checkout_label' => 'PayPal oder Sofort', 'connected_at' => now()]);
    // Onboarding not finished (no connected_at) — never offered.
    SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_UNZER)->create(['connected_at' => null]);

    $this->get('/pay/registration/'.$registrationId)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pay/Show')
            ->has('accounts', 2)
            ->where('accounts.0.provider', 'stripe')
            ->where('accounts.1.label', 'PayPal oder Sofort'));
});

it('starts the hosted checkout on the chosen account and records it on the payment', function (): void {
    $this->withBrand();
    // Both `/pay/registration/...` routes carry `auth` since #2 — a
    // declaration at checkout needs a subject to record it against.
    $this->actingAs(User::factory()->create());
    $registrationId = (string) Str::ulid();

    $order = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'settles_type' => 'registration',
        'settles_id' => $registrationId,
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);

    $mollie = SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_MOLLIE)
        ->create(['credentials' => ['api_key' => 'live_multi_key']]);

    app()->instance(MollieApi::class, new MultiProviderSettlementTestFakeMollieApi);

    $this->post('/pay/registration/'.$registrationId.'/start', ['account' => $mollie->id])
        ->assertRedirect('https://www.mollie.test/checkout/select-method/multi');

    $payment = Payment::withoutBrandScope()->where('order_id', $order->id)->firstOrFail();
    expect($payment->settlement_account_id)->toBe($mollie->id)
        ->and($payment->provider)->toBe('mollie');
});

it('refuses an account that is not offered, not this brand\'s, or missing', function (): void {
    $brand = $this->withBrand();
    // Both `/pay/registration/...` routes carry `auth` since #2 — a
    // declaration at checkout needs a subject to record it against.
    $this->actingAs(User::factory()->create());
    $registrationId = (string) Str::ulid();

    Order::factory()->create([
        'status' => OrderStatus::Pending,
        'settles_type' => 'registration',
        'settles_id' => $registrationId,
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);

    // Stripe with pending card_payments: connected, but not offered yet.
    $notOffered = SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_STRIPE)
        ->create(['capabilities' => ['card_payments' => 'pending']]);

    // A live, offered account — but on another brand entirely.
    $this->withBrand(Brand::factory()->create());
    $foreign = SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_MOLLIE)->create();
    $this->withBrand($brand);

    // A real Inertia POST sends X-Requested-With but Accept: text/html, so
    // Laravel's ValidationException redirects back with errors (302) —
    // exactly what a normal form submission does; the plain `post()` +
    // assertSessionHasErrors() pair matches that, and matches how the rest
    // of the app tests the same thing (e.g. PublicRegistrationFlowTest).
    $this->post('/pay/registration/'.$registrationId.'/start', ['account' => $notOffered->id])
        ->assertRedirect()
        ->assertSessionHasErrors('account');
    $this->post('/pay/registration/'.$registrationId.'/start', ['account' => $foreign->id])
        ->assertRedirect()
        ->assertSessionHasErrors('account');
    $this->post('/pay/registration/'.$registrationId.'/start', ['account' => (string) Str::ulid()])
        ->assertRedirect()
        ->assertSessionHasErrors('account');
});

it('refuses a second account once a payment succeeded', function (): void {
    $this->withBrand();
    // Both `/pay/registration/...` routes carry `auth` since #2 — a
    // declaration at checkout needs a subject to record it against.
    $this->actingAs(User::factory()->create());
    $registrationId = (string) Str::ulid();

    $order = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'settles_type' => 'registration',
        'settles_id' => $registrationId,
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);

    $stripe = SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_STRIPE)
        ->create(['capabilities' => ['card_payments' => 'active']]);
    $mollie = SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_MOLLIE)->create();

    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'settlement_account_id' => $stripe->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'amount' => '119.00',
        'currency' => 'EUR',
    ]);

    $this->post('/pay/registration/'.$registrationId.'/start', ['account' => $mollie->id])
        ->assertStatus(409);
});

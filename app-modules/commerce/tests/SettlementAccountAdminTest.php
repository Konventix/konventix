<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ViewErrorBag;
use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Actions\ConnectSettlementAccount;
use Konventix\Commerce\Events\SettlementAccountConnected;
use Konventix\Commerce\Exceptions\BrandAlreadyHasLiveProviderException;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeCheckoutSession;
use Konventix\Commerce\Settlement\Unzer\UnzerApi;
use Konventix\Commerce\Settlement\Unzer\UnzerPaymentSnapshot;
use Konventix\Commerce\Settlement\Unzer\UnzerPaypageSnapshot;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;

/**
 * ADR-0029/ADR-0039: the settlement-account console — connecting where a
 * brand's money goes. Stripe and Unzer SDK calls are faked at their seams.
 */
final class SettlementAdminTestFakeStripe implements StripeApi
{
    /** @var list<array{displayName: string, contactEmail: string, country: string}> */
    public array $accounts = [];

    /** @var list<array{accountId: string, returnUrl: string, refreshUrl: string}> */
    public array $links = [];

    /**
     * What Stripe refuses this call with, when the test wants the SDK's own
     * error rather than a happy path (#194).
     */
    public ?ApiErrorException $refuseWith = null;

    public function createCheckoutSession(array $params, string $stripeAccountId): StripeCheckoutSession
    {
        return new StripeCheckoutSession(id: 'cs_x', url: 'https://stripe.test/x');
    }

    public function createRefund(array $params, string $stripeAccountId): void {}

    public function createConnectedAccount(string $displayName, string $contactEmail, string $country): string
    {
        if ($this->refuseWith !== null) {
            throw $this->refuseWith;
        }

        $this->accounts[] = ['displayName' => $displayName, 'contactEmail' => $contactEmail, 'country' => $country];

        return 'acct_new_1';
    }

    public function createOnboardingLink(string $stripeAccountId, string $returnUrl, string $refreshUrl): string
    {
        $this->links[] = ['accountId' => $stripeAccountId, 'returnUrl' => $returnUrl, 'refreshUrl' => $refreshUrl];

        return 'https://connect.stripe.test/onboarding/'.$stripeAccountId;
    }

    public function fetchAccount(string $stripeAccountId): array
    {
        if ($this->refuseWith !== null) {
            throw $this->refuseWith;
        }

        return ['card_payments' => 'unknown'];
    }

    /**
     * Unused here: only `commerce:reconcile-payments` (#71) reads a session
     * back, and this fake never drives it.
     *
     * @return array{status: string, payment_status: string, payment_intent: ?string, payment_intent_status: ?string}
     */
    public function fetchCheckoutSession(string $sessionId, string $stripeAccountId): array
    {
        return [
            'status' => 'open',
            'payment_status' => 'unpaid',
            'payment_intent' => null,
            'payment_intent_status' => null,
        ];
    }
}

final class SettlementAdminTestFakeUnzer implements UnzerApi
{
    /** @var list<array{url: string, privateKey: string}> */
    public array $webhooks = [];

    public function createPaypage(float $amount, string $currency, string $orderId, string $returnUrl, string $privateKey): UnzerPaypageSnapshot
    {
        return new UnzerPaypageSnapshot(id: 'ppg_x', redirectUrl: 'https://unzer.test/x');
    }

    public function fetchPayment(string $paymentId, string $privateKey): UnzerPaymentSnapshot
    {
        return new UnzerPaymentSnapshot(id: $paymentId, state: 'pending', orderId: null);
    }

    public function refundPayment(string $paymentId, float $amount, string $privateKey): void {}

    public function registerWebhook(string $url, string $privateKey): void
    {
        $this->webhooks[] = ['url' => $url, 'privateKey' => $privateKey];
    }
}

/**
 * @return array{0: User, 1: Brand}
 */
function settlementAdminOperator(array $permissions = ['platform.access-admin', 'commerce.manage-settlement-account']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.settlement.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Operator Admin',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    $brand = test()->withBrand();

    return [$admin, $brand];
}

/**
 * The `provider` error the settlement console renders, read back from the
 * session. Laravel keeps the flashed `ViewErrorBag` as its array form once
 * the session has been serialised, so both shapes are accepted here.
 */
function settlementAdminProviderError(): string
{
    $errors = session('errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->first('provider');
    }

    return (string) (data_get($errors, 'default.messages.provider.0') ?? '');
}

it('403s an admin without the manage capability', function (): void {
    [$admin] = settlementAdminOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.settlement.test/admin/settlement-accounts')
        ->assertForbidden();
});

it('lists a brand\'s live accounts with offered flags and remaining connectable providers', function (): void {
    [$admin, $brand] = settlementAdminOperator();

    SettlementAccount::factory()->create([
        'brand_id' => $brand->id,
        'provider' => SettlementAccount::PROVIDER_MOLLIE,
        'credentials' => ['api_key' => 'k'],
    ]);
    SettlementAccount::factory()->create([
        'brand_id' => $brand->id,
        'provider' => SettlementAccount::PROVIDER_UNZER,
        'credentials' => ['private_key' => 'p'],
    ]);

    $this->actingAs($admin)
        ->get('http://admin.settlement.test/admin/settlement-accounts')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/settlement-accounts/Index')
            ->has('brands', 1)
            ->has('brands.0.accounts', 2)
            ->where('brands.0.accounts.0.provider', 'mollie')
            ->where('brands.0.accounts.0.pendingOnboarding', false)
            ->where('brands.0.accounts.0.offered', true)
            ->where('brands.0.accounts.1.provider', 'unzer')
            ->where('brands.0.connectableProviders', ['stripe']));
});

it('still offers the remaining providers once one is connected', function (): void {
    [$admin, $brand] = settlementAdminOperator();

    SettlementAccount::factory()->create([
        'brand_id' => $brand->id,
        'provider' => SettlementAccount::PROVIDER_MOLLIE,
        'credentials' => ['api_key' => 'k'],
    ]);

    $this->actingAs($admin)
        ->get('http://admin.settlement.test/admin/settlement-accounts')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('brands.0.connectableProviders', ['stripe', 'unzer']));
});

it('saves a custom checkout label', function (): void {
    [$admin, $brand] = settlementAdminOperator();

    $account = SettlementAccount::factory()->create([
        'brand_id' => $brand->id,
        'provider' => SettlementAccount::PROVIDER_MOLLIE,
        'credentials' => ['api_key' => 'k'],
    ]);

    $this->actingAs($admin)
        ->put('http://admin.settlement.test/admin/settlement-accounts/'.$account->id.'/label', [
            'checkoutLabel' => 'Pay by card',
        ])
        ->assertRedirect();

    expect($account->refresh()->checkout_label)->toBe('Pay by card');
});

it('connects Mollie in one step and emits the contract event', function (): void {
    [$admin, $brand] = settlementAdminOperator();

    Event::fake([SettlementAccountConnected::class]);

    $this->actingAs($admin)
        ->post('http://admin.settlement.test/admin/settlement-accounts/mollie', [
            'brand_id' => $brand->id,
            'api_key' => 'live_key_1234567890',
        ])
        ->assertRedirect();

    $account = SettlementAccount::withoutBrandScope()->where('brand_id', $brand->id)->sole();

    expect($account->provider)->toBe('mollie')
        ->and($account->credentials)->toBe(['api_key' => 'live_key_1234567890'])
        ->and($account->connected_at)->not->toBeNull();

    Event::assertDispatched(
        SettlementAccountConnected::class,
        fn (SettlementAccountConnected $e): bool => $e->brandId === $brand->id && $e->provider === 'mollie',
    );
});

it('connects Unzer and registers this deployment as the account webhook', function (): void {
    [$admin, $brand] = settlementAdminOperator();

    $unzer = new SettlementAdminTestFakeUnzer;
    app()->instance(UnzerApi::class, $unzer);

    $this->actingAs($admin)
        ->post('http://admin.settlement.test/admin/settlement-accounts/unzer', [
            'brand_id' => $brand->id,
            'private_key' => 's-priv-1234567890',
        ])
        ->assertRedirect();

    $account = SettlementAccount::withoutBrandScope()->where('brand_id', $brand->id)->sole();

    // Without this registration no Unzer payment would ever settle.
    expect($unzer->webhooks)->toHaveCount(1)
        ->and($unzer->webhooks[0]['url'])->toContain('/api/commerce/webhooks/unzer/'.$account->id)
        ->and($unzer->webhooks[0]['privateKey'])->toBe('s-priv-1234567890');
});

it('starts Stripe onboarding with an unconnected row and redirects to Stripe', function (): void {
    [$admin, $brand] = settlementAdminOperator();

    $stripe = new SettlementAdminTestFakeStripe;
    app()->instance(StripeApi::class, $stripe);

    Event::fake([SettlementAccountConnected::class]);

    $response = $this->actingAs($admin)
        ->post('http://admin.settlement.test/admin/settlement-accounts/stripe', [
            'brand_id' => $brand->id,
            'contact_email' => 'orga@example.test',
        ]);

    // Inertia::location: a plain request gets a real redirect to Stripe's
    // hosted onboarding (SPA requests would get 409 + X-Inertia-Location).
    $response->assertRedirect('https://connect.stripe.test/onboarding/acct_new_1');

    $account = SettlementAccount::withoutBrandScope()->where('brand_id', $brand->id)->sole();

    expect($account->provider)->toBe('stripe')
        ->and($account->provider_account_id)->toBe('acct_new_1')
        ->and($account->connected_at)->toBeNull();

    // Not connected until the organizer returns from onboarding.
    Event::assertNotDispatched(SettlementAccountConnected::class);

    expect($stripe->links[0]['returnUrl'])->toContain('/admin/settlement-accounts/stripe/return/'.$account->id);
});

it('marks the Stripe account connected on return, once', function (): void {
    [$admin, $brand] = settlementAdminOperator();

    $account = SettlementAccount::factory()->create([
        'brand_id' => $brand->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'provider_account_id' => 'acct_new_1',
        'connected_at' => null,
    ]);

    // The return also refreshes capabilities (a best-effort first read).
    app()->instance(StripeApi::class, new SettlementAdminTestFakeStripe);

    Event::fake([SettlementAccountConnected::class]);

    $this->actingAs($admin)
        ->get('http://admin.settlement.test/admin/settlement-accounts/stripe/return/'.$account->id)
        ->assertRedirect();
    $this->actingAs($admin)
        ->get('http://admin.settlement.test/admin/settlement-accounts/stripe/return/'.$account->id)
        ->assertRedirect();

    expect($account->refresh()->connected_at)->not->toBeNull()
        ->and($account->capabilities)->toBe(['card_payments' => 'unknown'])
        ->and($account->capabilities_checked_at)->not->toBeNull();

    Event::assertDispatchedTimes(SettlementAccountConnected::class, 1);
});

it('returns a validation error when connecting a second live account of the same provider over HTTP', function (): void {
    [$admin, $brand] = settlementAdminOperator();

    $this->actingAs($admin)
        ->post('http://admin.settlement.test/admin/settlement-accounts/mollie', [
            'brand_id' => $brand->id,
            'api_key' => 'live_key_1234567890',
        ])
        ->assertRedirect();

    $this->actingAs($admin)
        ->post('http://admin.settlement.test/admin/settlement-accounts/mollie', [
            'brand_id' => $brand->id,
            'api_key' => 'live_key_0987654321',
        ])
        ->assertSessionHasErrors('provider');

    expect(SettlementAccount::withoutBrandScope()->where('brand_id', $brand->id)->count())->toBe(1);
});

it('refuses a second live account of the same provider', function (): void {
    [$admin, $brand] = settlementAdminOperator();

    $this->actingAs($admin)
        ->post('http://admin.settlement.test/admin/settlement-accounts/mollie', [
            'brand_id' => $brand->id,
            'api_key' => 'live_key_1234567890',
        ])
        ->assertRedirect();

    // Exercised through the Action directly, the way `ConnectSettlementAccount`
    // itself throws it — the friendly `back()->withErrors()` at the HTTP
    // boundary is covered by the test above.
    expect(fn () => app(ConnectSettlementAccount::class)->handle(
        $brand->id,
        SettlementAccount::PROVIDER_MOLLIE,
        credentials: ['api_key' => 'live_key_0987654321'],
    ))->toThrow(BrandAlreadyHasLiveProviderException::class);

    expect(SettlementAccount::withoutBrandScope()->where('brand_id', $brand->id)->count())->toBe(1);

    // A different provider for the same brand is legal now (ADR-0046): the
    // admin console connects it right through the same HTTP endpoint shape.
    app()->instance(StripeApi::class, new SettlementAdminTestFakeStripe);

    $this->actingAs($admin)
        ->post('http://admin.settlement.test/admin/settlement-accounts/stripe', [
            'brand_id' => $brand->id,
            'contact_email' => 'orga@example.test',
        ])
        ->assertRedirect();

    expect(SettlementAccount::withoutBrandScope()->where('brand_id', $brand->id)->count())->toBe(2);
});

it('disconnects the live account and falls back to manual booking', function (): void {
    [$admin, $brand] = settlementAdminOperator();

    $account = SettlementAccount::factory()->create([
        'brand_id' => $brand->id,
        'provider' => SettlementAccount::PROVIDER_MOLLIE,
        'credentials' => ['api_key' => 'k'],
    ]);

    $this->actingAs($admin)
        ->post('http://admin.settlement.test/admin/settlement-accounts/'.$account->id.'/disconnect')
        ->assertRedirect();

    expect($account->refresh()->disconnected_at)->not->toBeNull()
        ->and(SettlementAccount::liveAccountsFor($brand->id))->toBeEmpty();
});

/**
 * #194: a deployment without the Stripe platform key answered the connect
 * form with a bare 500, because `StripeApi`'s binding threw before the
 * controller body ran. The operator now reads what is missing and where it
 * is configured, on the page they submitted from.
 */
it('names the missing Stripe platform key instead of answering 500', function (): void {
    [$admin, $brand] = settlementAdminOperator();

    // phpunit.xml leaves STRIPE_SECRET empty on purpose; not binding a fake
    // here is exactly the dev instance's situation.
    expect(config('services.stripe.secret'))->toBeEmpty();

    $response = $this->actingAs($admin)
        ->post('http://admin.settlement.test/admin/settlement-accounts/stripe', [
            'brand_id' => $brand->id,
            'contact_email' => 'orga@example.test',
        ]);

    $response->assertRedirect('http://admin.settlement.test/admin/settlement-accounts');

    $error = settlementAdminProviderError();

    expect($error)->toContain('STRIPE_SECRET')
        ->and($error)->toContain('STRIPE_WEBHOOK_SECRET')
        ->and($error)->toContain('services.stripe.secret')
        ->and($error)->toContain('secrets.payments')
        ->and($error)->toContain('ADR-0039');

    // Nothing was created: the brand still has no Stripe row to clean up.
    expect(SettlementAccount::withoutBrandScope()->where('brand_id', $brand->id)->count())->toBe(0);
});

it('repeats Stripe\'s own refusal, with its code and doc URL', function (): void {
    [$admin, $brand] = settlementAdminOperator();

    $stripe = new SettlementAdminTestFakeStripe;
    $stripe->refuseWith = InvalidRequestException::factory(
        'Accounts v2 is not enabled for your sandbox merchant acct_1234.',
        400,
        null,
        ['error' => [
            'message' => 'Accounts v2 is not enabled for your sandbox merchant acct_1234.',
            'code' => 'accounts_v2_not_enabled',
            'doc_url' => 'https://docs.stripe.com/error-codes#accounts-v2-not-enabled',
        ]],
        null,
        'accounts_v2_not_enabled',
    );
    app()->instance(StripeApi::class, $stripe);

    $response = $this->actingAs($admin)
        ->post('http://admin.settlement.test/admin/settlement-accounts/stripe', [
            'brand_id' => $brand->id,
            'contact_email' => 'orga@example.test',
        ]);

    $response->assertRedirect('http://admin.settlement.test/admin/settlement-accounts');

    $error = settlementAdminProviderError();

    expect($error)->toContain('Stripe refused the connection:')
        ->and($error)->toContain('Accounts v2 is not enabled for your sandbox merchant acct_1234.')
        ->and($error)->toContain('accounts_v2_not_enabled')
        ->and($error)->toContain('https://docs.stripe.com/error-codes#accounts-v2-not-enabled');

    expect(SettlementAccount::withoutBrandScope()->where('brand_id', $brand->id)->count())->toBe(0);
});

it('still connects the account on return when the capability read is refused', function (): void {
    [$admin, $brand] = settlementAdminOperator();

    $account = SettlementAccount::factory()->create([
        'brand_id' => $brand->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'provider_account_id' => 'acct_new_1',
        'connected_at' => null,
    ]);

    $stripe = new SettlementAdminTestFakeStripe;
    $stripe->refuseWith = InvalidRequestException::factory(
        'No such account: acct_new_1',
        404,
        null,
        ['error' => ['message' => 'No such account: acct_new_1', 'code' => 'resource_missing']],
        null,
        'resource_missing',
    );
    app()->instance(StripeApi::class, $stripe);

    $response = $this->actingAs($admin)
        ->get('http://admin.settlement.test/admin/settlement-accounts/stripe/return/'.$account->id);

    $response->assertRedirect('http://admin.settlement.test/admin/settlement-accounts');

    $error = settlementAdminProviderError();

    expect($error)->toContain('Stripe refused the connection:')
        ->and($error)->toContain('No such account: acct_new_1');

    // Onboarding did complete — the row stays connected; only the
    // best-effort capability read failed, and the daily sweep retries it.
    expect($account->refresh()->connected_at)->not->toBeNull()
        ->and($account->capabilities_checked_at)->toBeNull();
});

it('names the missing Stripe platform key on the onboarding return too', function (): void {
    [$admin, $brand] = settlementAdminOperator();

    $account = SettlementAccount::factory()->create([
        'brand_id' => $brand->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'provider_account_id' => 'acct_new_1',
        'connected_at' => null,
    ]);

    $response = $this->actingAs($admin)
        ->get('http://admin.settlement.test/admin/settlement-accounts/stripe/return/'.$account->id);

    $response->assertRedirect('http://admin.settlement.test/admin/settlement-accounts');

    expect(settlementAdminProviderError())->toContain('STRIPE_SECRET');
    expect($account->refresh()->connected_at)->not->toBeNull();
});

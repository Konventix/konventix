<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Events\PaymentReversed;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Mollie\MollieApi;
use Konventix\Commerce\Settlement\Mollie\MolliePaymentSnapshot;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeCheckoutSession;
use Konventix\Commerce\Settlement\Unzer\UnzerApi;
use Konventix\Commerce\Settlement\Unzer\UnzerPaymentSnapshot;
use Konventix\Commerce\Settlement\Unzer\UnzerPaypageSnapshot;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;

/**
 * `commerce:reconcile-payments` (#71): the scheduled question the webhook
 * already answers, asked again against the provider. ADR-0057 point 7's
 * last sentence is what makes it more than a belt-and-braces sweep — "a
 * lost dispute notification is found by the sweep, not by the payer".
 *
 * Every provider call goes through a fake of the same seam the adapters
 * use; no SDK call leaves the process.
 */
final class ReconciliationTestStripeApi implements StripeApi
{
    /** @var list<array{sessionId: string, account: string}> */
    public array $sessionFetches = [];

    /** @var array{status: string, payment_status: string, payment_intent: ?string} */
    public array $session = ['status' => 'open', 'payment_status' => 'unpaid', 'payment_intent' => null];

    public function createCheckoutSession(array $params, string $stripeAccountId): StripeCheckoutSession
    {
        return new StripeCheckoutSession(id: 'cs_unused', url: 'https://checkout.stripe.test/unused');
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
        return ['card_payments' => 'unknown'];
    }

    public function fetchCheckoutSession(string $sessionId, string $stripeAccountId): array
    {
        $this->sessionFetches[] = ['sessionId' => $sessionId, 'account' => $stripeAccountId];

        return $this->session;
    }
}

final class ReconciliationTestMollieApi implements MollieApi
{
    /** @var list<string> */
    public array $fetches = [];

    public string $status = 'open';

    /** @var list<array{id: string, amount: string, reason: ?string, createdAt: string}> */
    public array $chargebacks = [];

    /** @var list<array{id: string, amount: string, reason: ?string, createdAt: string, status: string}> */
    public array $refunds = [];

    public function createPayment(array $params, string $apiKey): MolliePaymentSnapshot
    {
        return new MolliePaymentSnapshot(id: 'tr_unused', status: 'open');
    }

    public function fetchPayment(string $paymentId, string $apiKey): MolliePaymentSnapshot
    {
        $this->fetches[] = $paymentId;

        return new MolliePaymentSnapshot(
            id: $paymentId,
            status: $this->status,
            chargebacks: $this->chargebacks,
            refunds: $this->refunds,
        );
    }

    public function refundPayment(string $paymentId, array $params, string $apiKey): void {}
}

final class ReconciliationTestUnzerApi implements UnzerApi
{
    /** @var list<string> */
    public array $fetches = [];

    public string $state = 'completed';

    /** @var list<array{id: string, amount: string, currency: string, date: ?string}> */
    public array $chargebacks = [];

    public function createPaypage(float $amount, string $currency, string $orderId, string $returnUrl, string $privateKey): UnzerPaypageSnapshot
    {
        return new UnzerPaypageSnapshot(id: 'ppg_unused', redirectUrl: 'https://paypage.unzer.test/unused');
    }

    public function fetchPayment(string $paymentId, string $privateKey): UnzerPaymentSnapshot
    {
        $this->fetches[] = $paymentId;

        return new UnzerPaymentSnapshot(
            id: $paymentId,
            state: $this->state,
            orderId: null,
            chargebacks: $this->chargebacks,
        );
    }

    public function refundPayment(string $paymentId, float $amount, string $privateKey): void {}

    public function registerWebhook(string $url, string $privateKey): void {}
}

/**
 * @return array{0: Order, 1: Payment, 2: SettlementAccount}
 */
function reconciliationTestStripePending(int $ageMinutes = 60): array
{
    test()->withBrand();

    $account = SettlementAccount::factory()->create([
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'provider_account_id' => 'acct_recon_1',
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'settlement_account_id' => $account->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'external_id' => 'cs_recon_1',
        'amount' => '119.00',
        'currency' => 'EUR',
    ]);

    reconciliationTestAge($payment, $ageMinutes);

    return [$order, $payment, $account];
}

function reconciliationTestAge(Payment $payment, int $ageMinutes): void
{
    // `created_at` is what the sweep windows on, and Eloquent refuses to
    // backdate it through the model's own timestamps.
    DB::table('payments')->where('id', $payment->id)->update(['created_at' => now()->subMinutes($ageMinutes)]);
    $payment->refresh();
}

/**
 * @return array{0: Order, 1: Payment}
 */
function reconciliationTestMollieSettled(): array
{
    test()->withBrand();

    $account = SettlementAccount::factory()->create([
        'provider' => SettlementAccount::PROVIDER_MOLLIE,
        'provider_account_id' => null,
        'credentials' => ['api_key' => 'live_recon_key'],
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::Paid,
        'total_gross' => '119.00',
        'currency' => 'EUR',
        'paid_at' => now()->subDays(3),
    ]);

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'settlement_account_id' => $account->id,
        'provider' => SettlementAccount::PROVIDER_MOLLIE,
        'external_id' => 'tr_recon_1',
        'amount' => '119.00',
        'currency' => 'EUR',
        'received_at' => now()->subDays(3),
    ]);

    return [$order, $payment];
}

function reconciliationTestOperator(string $brandId): User
{
    PermissionModel::query()->firstOrCreate(['name' => 'commerce.manage-products', 'guard_name' => 'web']);

    $role = Role::query()->create(['name' => 'Recon Manager', 'guard_name' => 'web', 'brand_id' => $brandId]);
    $role->givePermissionTo(['commerce.manage-products']);

    $user = User::factory()->create();

    event(new UserRegistered(
        userId: $user->id,
        brandId: $brandId,
        email: $user->email,
        displayName: $user->name,
        username: $user->username,
        locale: $user->locale,
        registeredAt: now()->toIso8601String(),
    ));

    DB::table('model_has_roles')->insert([
        'role_id' => $role->id,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->id,
        'brand_id' => $brandId,
    ]);

    return $user;
}

it('settles a pending Stripe payment the webhook never reported', function (): void {
    [$order, $payment, $account] = reconciliationTestStripePending();

    $stripe = new ReconciliationTestStripeApi;
    $stripe->session = ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_recon_1'];
    app()->instance(StripeApi::class, $stripe);

    Event::fake([OrderPaid::class]);

    $this->artisan('commerce:reconcile-payments')->assertSuccessful();

    expect($stripe->sessionFetches)->toBe([['sessionId' => 'cs_recon_1', 'account' => 'acct_recon_1']])
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->provider_transaction_id)->toBe('pi_recon_1')
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    Event::assertDispatched(OrderPaid::class);
});

it('fails a pending Stripe payment whose session expired', function (): void {
    [$order, $payment] = reconciliationTestStripePending();

    $stripe = new ReconciliationTestStripeApi;
    $stripe->session = ['status' => 'expired', 'payment_status' => 'unpaid', 'payment_intent' => null];
    app()->instance(StripeApi::class, $stripe);

    Event::fake([PaymentFailed::class]);

    $this->artisan('commerce:reconcile-payments')->assertSuccessful();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Expired)
        ->and($payment->failure_reason)->toBe('checkout_expired');

    Event::assertDispatched(PaymentFailed::class);
});

it('leaves a payment younger than fifteen minutes to the webhook', function (): void {
    [, $payment] = reconciliationTestStripePending(ageMinutes: 5);

    $stripe = new ReconciliationTestStripeApi;
    $stripe->session = ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_recon_2'];
    app()->instance(StripeApi::class, $stripe);

    $this->artisan('commerce:reconcile-payments')->assertSuccessful();

    expect($stripe->sessionFetches)->toBe([])
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('leaves a pending payment older than seven days alone', function (): void {
    [, $payment] = reconciliationTestStripePending(ageMinutes: 60 * 24 * 8);

    $stripe = new ReconciliationTestStripeApi;
    $stripe->session = ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_recon_3'];
    app()->instance(StripeApi::class, $stripe);

    $this->artisan('commerce:reconcile-payments')->assertSuccessful();

    expect($stripe->sessionFetches)->toBe([])
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('settles a pending Mollie payment the provider reports as paid', function (): void {
    test()->withBrand();

    $account = SettlementAccount::factory()->create([
        'provider' => SettlementAccount::PROVIDER_MOLLIE,
        'provider_account_id' => null,
        'credentials' => ['api_key' => 'live_recon_key'],
    ]);

    $order = Order::factory()->create(['status' => OrderStatus::Pending, 'total_gross' => '50.00', 'currency' => 'EUR']);
    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'settlement_account_id' => $account->id,
        'provider' => SettlementAccount::PROVIDER_MOLLIE,
        'external_id' => 'tr_recon_pending',
        'amount' => '50.00',
        'currency' => 'EUR',
    ]);
    reconciliationTestAge($payment, 30);

    $mollie = new ReconciliationTestMollieApi;
    $mollie->status = 'paid';
    app()->instance(MollieApi::class, $mollie);

    $this->artisan('commerce:reconcile-payments')->assertSuccessful();

    expect($mollie->fetches)->toBe(['tr_recon_pending'])
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('finds a Mollie chargeback the webhook never delivered, and a replay is a no-op', function (): void {
    [$order, $payment] = reconciliationTestMollieSettled();

    $mollie = new ReconciliationTestMollieApi;
    $mollie->status = 'paid';
    $mollie->chargebacks = [[
        'id' => 'chb_recon_1',
        'amount' => '119.00',
        'reason' => 'AC04',
        'createdAt' => now()->subDay()->toIso8601String(),
    ]];
    app()->instance(MollieApi::class, $mollie);

    Event::fake([PaymentReversed::class]);

    $this->artisan('commerce:reconcile-payments')->assertSuccessful();
    $this->artisan('commerce:reconcile-payments')->assertSuccessful();

    expect(PaymentReversal::withoutBrandScope()->count())->toBe(1)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Reversed)
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReversed);

    $reversal = PaymentReversal::withoutBrandScope()->sole();

    expect($reversal->kind)->toBe(ReversalKind::Return)
        ->and($reversal->status)->toBe(ReversalStatus::Final)
        ->and($reversal->reason_code)->toBe('AC04');

    Event::assertDispatchedTimes(PaymentReversed::class, 1);
});

it('finds an Unzer chargeback on a settled payment through the stored transaction id', function (): void {
    test()->withBrand();

    $account = SettlementAccount::factory()->create([
        'provider' => SettlementAccount::PROVIDER_UNZER,
        'provider_account_id' => null,
        'credentials' => ['private_key' => 's-priv-recon'],
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::Paid,
        'total_gross' => '80.00',
        'currency' => 'EUR',
        'paid_at' => now()->subDays(2),
    ]);

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'settlement_account_id' => $account->id,
        'provider' => SettlementAccount::PROVIDER_UNZER,
        'external_id' => 'ppg_recon_1',
        'provider_transaction_id' => 's-pay-recon-1',
        'amount' => '80.00',
        'currency' => 'EUR',
        'received_at' => now()->subDays(2),
    ]);

    $unzer = new ReconciliationTestUnzerApi;
    $unzer->state = 'chargeback';
    $unzer->chargebacks = [[
        'id' => 's-cbk-recon-1',
        'amount' => '80.00',
        'currency' => 'EUR',
        'date' => now()->subDay()->format('Y-m-d H:i:s'),
    ]];
    app()->instance(UnzerApi::class, $unzer);

    $this->artisan('commerce:reconcile-payments')->assertSuccessful();

    expect($unzer->fetches)->toBe(['s-pay-recon-1'])
        ->and(PaymentReversal::withoutBrandScope()->sole()->provider_reference)->toBe('s-cbk-recon-1')
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReversed);
});

it('tells the shop operators that reconciliation, not the webhook, found the payment', function (): void {
    [, $payment] = reconciliationTestStripePending();
    $holder = reconciliationTestOperator($payment->brand_id);

    $stripe = new ReconciliationTestStripeApi;
    $stripe->session = ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_recon_4'];
    app()->instance(StripeApi::class, $stripe);

    Queue::fake();

    $this->artisan('commerce:reconcile-payments')
        ->expectsOutputToContain('1 corrected')
        ->assertSuccessful();

    Queue::assertPushedOn('messaging', SendQueuedMessage::class, function (SendQueuedMessage $job) use ($holder): bool {
        $payload = $job->message->payload->toArray();

        return $job->message->templateKey === 'commerce.operations-notice'
            && $job->message->recipientId === $holder->id
            && $payload['kind'] === 'payment-reconciled';
    });
});

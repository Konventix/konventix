<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Konventix\Commerce\Actions\RecordPaymentSuccess;
use Konventix\Commerce\Actions\RefundOrder;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\OrderRefunded;
use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Events\PaymentReversed;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Mollie\MollieApi;
use Konventix\Commerce\Settlement\Mollie\MolliePaymentSnapshot;
use Konventix\Commerce\Settlement\SettlementAdapter;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeCheckoutSession;
use Konventix\Commerce\Settlement\Stripe\StripeSessionNotFoundException;
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

    /** @var array{status: string, payment_status: string, payment_intent: ?string, payment_intent_status: ?string} */
    public array $session = [
        'status' => 'open',
        'payment_status' => 'unpaid',
        'payment_intent' => null,
        'payment_intent_status' => null,
    ];

    /**
     * Runs just before the fake answers — the seam a test uses to simulate a
     * webhook winning the race while the sweep was already asking.
     *
     * @var ?callable(): void
     */
    public $beforeAnswering = null;

    /**
     * Simulates Stripe's own "No such checkout.session" answer (#286):
     * `fetchCheckoutSession()` throws instead of returning `$session`, the
     * same terminal answer `SdkStripeApi` translates a real
     * `resource_missing` `InvalidRequestException` into.
     */
    public bool $sessionNotFound = false;

    public function createCheckoutSession(array $params): StripeCheckoutSession
    {
        return new StripeCheckoutSession(id: 'cs_unused', url: 'https://checkout.stripe.test/unused');
    }

    /**
     * What Stripe holds against the payment intent (#235). Empty unless a
     * test says otherwise, and every question is recorded so a test can
     * prove the sweep asks once per payment.
     *
     * @var array{disputes: list<array{id: string, status: string, amount: int, currency: string, reason: ?string, fee: ?int, created: ?int}>, refunds: list<array{id: string, amount: int, currency: string, reason: ?string, charge: ?string, created: ?int}>}
     */
    public array $reversals = ['disputes' => [], 'refunds' => []];

    /** @var list<string> */
    public array $reversalFetches = [];

    /**
     * @return array{disputes: list<array{id: string, status: string, amount: int, currency: string, reason: ?string, fee: ?int, created: ?int}>, refunds: list<array{id: string, amount: int, currency: string, reason: ?string, charge: ?string, created: ?int}>}
     */
    public function fetchPaymentIntentReversals(string $paymentIntentId): array
    {
        $this->reversalFetches[] = $paymentIntentId;

        return $this->reversals;
    }

    public function createRefund(array $params): string
    {
        return 're_test_fake';
    }

    /**
     * @return array{status: string, payment_status: string, payment_intent: ?string, payment_intent_status: ?string}
     */
    public function fetchCheckoutSession(string $sessionId): array
    {
        $this->sessionFetches[] = $sessionId;

        if ($this->beforeAnswering !== null) {
            ($this->beforeAnswering)();
        }

        if ($this->sessionNotFound) {
            throw StripeSessionNotFoundException::forSession($sessionId);
        }

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

    public function refundPayment(string $paymentId, array $params, string $apiKey): string
    {
        return 're_test_fake';
    }
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

    /**
     * Order ids the pending pass asked about (#191) — our own `Payment`
     * ULIDs, which is what the Paypage carries as its `orderId`.
     *
     * @var list<string>
     */
    public array $orderIdFetches = [];

    /**
     * The Unzer payment id the Paypage produced once it was paid, or null
     * for a Paypage Unzer holds no payment for at all — the ordinary answer
     * for one nobody has paid yet.
     */
    public ?string $pendingPaymentId = null;

    public string $pendingState = 'pending';

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

    public function fetchPaymentByOrderId(string $orderId, string $privateKey): ?UnzerPaymentSnapshot
    {
        $this->orderIdFetches[] = $orderId;

        if ($this->pendingPaymentId === null) {
            return null;
        }

        return new UnzerPaymentSnapshot(
            id: $this->pendingPaymentId,
            state: $this->pendingState,
            orderId: $orderId,
        );
    }

    public function refundPayment(string $paymentId, float $amount, string $privateKey): string
    {
        return 'chg_test_fake';
    }

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

/**
 * A settled Stripe payment, the population #235 added to the settled pass:
 * the payment intent is what a dispute or a refund names.
 *
 * @return array{0: Order, 1: Payment}
 */
function reconciliationTestStripeSettled(): array
{
    test()->withBrand();

    $account = SettlementAccount::factory()->create([
        'provider' => SettlementAccount::PROVIDER_STRIPE,
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::Paid,
        'total_gross' => '119.00',
        'currency' => 'EUR',
        'paid_at' => now()->subDay(),
    ]);

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'settlement_account_id' => $account->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'external_id' => 'cs_recon_settled',
        'provider_transaction_id' => 'pi_recon_settled',
        'amount' => '119.00',
        'currency' => 'EUR',
        'received_at' => now()->subDay(),
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
    $stripe->session = ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_recon_1', 'payment_intent_status' => 'succeeded'];
    app()->instance(StripeApi::class, $stripe);

    Event::fake([OrderPaid::class]);

    $this->artisan('commerce:reconcile-payments')->assertSuccessful();

    // Read back on this instance's own account (ADR-0058): no account id.
    expect($stripe->sessionFetches)->toBe(['cs_recon_1'])
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->provider_transaction_id)->toBe('pi_recon_1')
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    Event::assertDispatched(OrderPaid::class);
});

it('fails a pending Stripe payment whose session expired', function (): void {
    [$order, $payment] = reconciliationTestStripePending();

    $stripe = new ReconciliationTestStripeApi;
    $stripe->session = ['status' => 'expired', 'payment_status' => 'unpaid', 'payment_intent' => null, 'payment_intent_status' => null];
    app()->instance(StripeApi::class, $stripe);

    Event::fake([PaymentFailed::class]);

    $this->artisan('commerce:reconcile-payments')->assertSuccessful();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Expired)
        ->and($payment->failure_reason)->toBe('checkout_expired');

    Event::assertDispatched(PaymentFailed::class);
});

/**
 * #286: Stripe answering "No such checkout.session" is a definite answer,
 * not a failure to ask. Two rows on dev hit exactly this against orders
 * the buyer had already abandoned and that `ExpireCarts` had cancelled —
 * the sweep used to catch the SDK's exception, log a stack trace, and
 * leave the payment `pending` forever, which is what kept
 * `KonventixWebhookSilence` firing.
 */
it('expires a pending Stripe payment whose session vanished at the provider, when its order is already cancelled', function (): void {
    test()->withBrand();

    $account = SettlementAccount::factory()->create([
        'provider' => SettlementAccount::PROVIDER_STRIPE,
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::Cancelled,
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'settlement_account_id' => $account->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'external_id' => 'cs_recon_vanished_terminal',
        'amount' => '119.00',
        'currency' => 'EUR',
    ]);
    reconciliationTestAge($payment, 60);

    $stripe = new ReconciliationTestStripeApi;
    $stripe->sessionNotFound = true;
    app()->instance(StripeApi::class, $stripe);

    Event::fake([PaymentFailed::class]);

    $this->artisan('commerce:reconcile-payments')
        ->expectsOutputToContain('1 resolved (session vanished at provider)')
        ->assertSuccessful();

    expect($stripe->sessionFetches)->toBe(['cs_recon_vanished_terminal'])
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Expired)
        ->and($payment->failure_reason)->toBe('provider_session_not_found');

    Event::assertDispatched(PaymentFailed::class);
});

/**
 * The other half of #286: an order still open when its Stripe session
 * vanishes is a case for a human, not this sweep — money may genuinely be
 * in doubt, and nothing here may decide that silently.
 */
it('leaves a pending Stripe payment alone and flags it for review when its session vanished but the order is still open', function (): void {
    [$order, $payment] = reconciliationTestStripePending();

    $stripe = new ReconciliationTestStripeApi;
    $stripe->sessionNotFound = true;
    app()->instance(StripeApi::class, $stripe);

    Event::fake([PaymentFailed::class]);

    $this->artisan('commerce:reconcile-payments')
        ->expectsOutputToContain(strtoupper(substr($order->id, -8)))
        ->expectsOutputToContain('1 flagged for review (session vanished at provider, order still open)')
        ->assertSuccessful();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payment->failure_reason)->toBeNull();

    Event::assertNotDispatched(PaymentFailed::class);
});

it('leaves a payment younger than fifteen minutes to the webhook', function (): void {
    [, $payment] = reconciliationTestStripePending(ageMinutes: 5);

    $stripe = new ReconciliationTestStripeApi;
    $stripe->session = ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_recon_2', 'payment_intent_status' => 'succeeded'];
    app()->instance(StripeApi::class, $stripe);

    $this->artisan('commerce:reconcile-payments')->assertSuccessful();

    expect($stripe->sessionFetches)->toBe([])
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('leaves a pending payment older than seven days alone', function (): void {
    [, $payment] = reconciliationTestStripePending(ageMinutes: 60 * 24 * 8);

    $stripe = new ReconciliationTestStripeApi;
    $stripe->session = ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_recon_3', 'payment_intent_status' => 'succeeded'];
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
    $stripe->session = ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_recon_4', 'payment_intent_status' => 'succeeded'];
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

it('fails a pending Stripe payment whose asynchronous attempt was refused', function (): void {
    [, $payment] = reconciliationTestStripePending();

    // A lost `checkout.session.async_payment_failed`: the session completed
    // while still unpaid (SEPA, bank transfer) and the intent fell back to
    // wanting a payment method. `status` never becomes `expired`, so the
    // session alone cannot say the attempt failed.
    $stripe = new ReconciliationTestStripeApi;
    $stripe->session = [
        'status' => 'complete',
        'payment_status' => 'unpaid',
        'payment_intent' => 'pi_recon_async',
        'payment_intent_status' => 'requires_payment_method',
    ];
    app()->instance(StripeApi::class, $stripe);

    Event::fake([PaymentFailed::class]);

    $this->artisan('commerce:reconcile-payments')->assertSuccessful();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe('async_payment_failed');

    Event::assertDispatched(PaymentFailed::class);
});

it('leaves an asynchronous attempt that is still processing alone', function (): void {
    [, $payment] = reconciliationTestStripePending();

    $stripe = new ReconciliationTestStripeApi;
    $stripe->session = [
        'status' => 'complete',
        'payment_status' => 'unpaid',
        'payment_intent' => 'pi_recon_processing',
        'payment_intent_status' => 'processing',
    ];
    app()->instance(StripeApi::class, $stripe);

    $this->artisan('commerce:reconcile-payments')->assertSuccessful();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('counts nothing and tells nobody when a webhook won the race', function (): void {
    [, $payment] = reconciliationTestStripePending();
    reconciliationTestOperator($payment->brand_id);

    // The webhook settles the row while the sweep is mid-question. The
    // sweep's own `RecordPaymentSuccess` is then a no-op, and claiming "the
    // notification never arrived" would be false.
    $stripe = new ReconciliationTestStripeApi;
    $stripe->session = [
        'status' => 'complete',
        'payment_status' => 'paid',
        'payment_intent' => 'pi_recon_race',
        'payment_intent_status' => 'succeeded',
    ];
    $stripe->beforeAnswering = function () use ($payment): void {
        app(RecordPaymentSuccess::class)->handle($payment->fresh());
    };
    app()->instance(StripeApi::class, $stripe);

    Queue::fake();

    $this->artisan('commerce:reconcile-payments')
        ->expectsOutputToContain('0 corrected')
        ->assertSuccessful();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded);

    Queue::assertNotPushed(SendQueuedMessage::class);
});

it('records every transferred dashboard refund in one snapshot, not just the first', function (): void {
    [$order, $payment] = reconciliationTestMollieSettled();

    $mollie = new ReconciliationTestMollieApi;
    $mollie->status = 'paid';
    $mollie->refunds = [
        [
            'id' => 're_recon_1',
            'amount' => '60.00',
            'reason' => 'Teilerstattung 1',
            'createdAt' => now()->subDay()->toIso8601String(),
            'status' => 'refunded',
        ],
        [
            'id' => 're_recon_2',
            'amount' => '59.00',
            'reason' => 'Teilerstattung 2',
            'createdAt' => now()->subHours(2)->toIso8601String(),
            'status' => 'refunded',
        ],
        // Not transferred yet: money that has not moved is not a reversal.
        [
            'id' => 're_recon_3',
            'amount' => '10.00',
            'reason' => 'noch offen',
            'createdAt' => now()->toIso8601String(),
            'status' => 'pending',
        ],
    ];
    app()->instance(MollieApi::class, $mollie);

    $this->artisan('commerce:reconcile-payments')->assertSuccessful();

    $reversals = PaymentReversal::withoutBrandScope()
        ->where('order_id', $order->id)
        ->orderBy('provider_reference')
        ->get();

    expect($reversals)->toHaveCount(2)
        ->and($reversals->pluck('provider_reference')->all())->toBe(['re_recon_1', 're_recon_2'])
        ->and($reversals->every(fn (PaymentReversal $r): bool => $r->kind === ReversalKind::ExternalRefund))->toBeTrue()
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded);
});

/**
 * I3: the settled pass re-read every Mollie and Unzer payment of the last
 * ninety days on every hourly run — one `fetchPayment` per settled payment
 * per hour, at the provider, for a question whose answer changes at most
 * once. The ledger ruling was a daily settled pass, so the two passes are
 * separable and the schedule runs them on their own cadences.
 */
it('asks the provider only about pending payments with --pending', function (): void {
    [, $payment] = reconciliationTestMollieSettled();

    $mollie = new ReconciliationTestMollieApi;
    $mollie->status = 'paid';
    $mollie->chargebacks = [[
        'id' => 'chb_hourly_1',
        'amount' => '119.00',
        'reason' => 'AC04',
        'createdAt' => now()->subDay()->toIso8601String(),
    ]];
    app()->instance(MollieApi::class, $mollie);

    $this->artisan('commerce:reconcile-payments', ['--pending' => true])->assertSuccessful();

    expect($mollie->fetches)->toBe([])
        ->and(PaymentReversal::withoutBrandScope()->count())->toBe(0)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded);
});

it('asks the provider only about settled payments with --settled', function (): void {
    [$order, $payment] = reconciliationTestStripePending();

    $stripe = new ReconciliationTestStripeApi;
    $stripe->session = ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_recon_1', 'payment_intent_status' => 'succeeded'];
    app()->instance(StripeApi::class, $stripe);

    $this->artisan('commerce:reconcile-payments', ['--settled' => true])->assertSuccessful();

    expect($stripe->sessionFetches)->toBe([])
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);
});

it('runs the pending pass hourly and the settled pass daily', function (): void {
    $entries = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'commerce:reconcile-payments'))
        ->mapWithKeys(fn (ScheduledEvent $event): array => [$event->expression => (string) $event->command]);

    expect($entries)->toHaveCount(2)
        ->and($entries->get('0 * * * *'))->toContain('--pending')
        ->and($entries->get('40 6 * * *'))->toContain('--settled');
});

/**
 * A provider adapter that refunds and answers with the reference, so a
 * console refund can be made against the Stripe provider without an SDK
 * (#247).
 */
final class ReconciliationTestRefundingAdapter implements SettlementAdapter
{
    public function markOrderPaid(string $accountId, Order $order, string $amountPaid, string $currency, string $settlementMethod): Order
    {
        return $order;
    }

    public function refund(string $accountId, Order $order, string $amount, string $currency): ?string
    {
        return 're_console_1';
    }
}

it('adds no second reversal for a refund the console itself made (#235, #247)', function (): void {
    // The invariant #247's "no migration" argument rests on: the console
    // refund stores its `re_…` on the reversal row, so when the #235 sweep
    // lists the same refund at Stripe it converges on that row instead of
    // recording an external refund against an order we refunded ourselves —
    // which would credit-note it twice and report a lost notification that
    // never existed.
    [$order, $payment] = reconciliationTestStripeSettled();
    Invoice::factory()->forOrder($order)->create();

    app(SettlementAdapterRegistry::class)->register(
        SettlementAccount::PROVIDER_STRIPE,
        ReconciliationTestRefundingAdapter::class,
    );

    Event::fake([OrderRefunded::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    expect(PaymentReversal::withoutBrandScope()->sole()->kind)->toBe(ReversalKind::Refund);

    $stripe = new ReconciliationTestStripeApi;
    $stripe->reversals = [
        'disputes' => [],
        'refunds' => [[
            'id' => 're_console_1',
            'amount' => 11900,
            'currency' => 'eur',
            'reason' => null,
            'charge' => 'ch_console_1',
            'created' => now()->getTimestamp(),
        ]],
    ];
    app()->instance(StripeApi::class, $stripe);

    $this->artisan('commerce:reconcile-payments --settled')
        ->expectsOutputToContain('0 reversal(s) found')
        ->assertSuccessful();

    $reversal = PaymentReversal::withoutBrandScope()->sole();

    expect($stripe->reversalFetches)->toBe(['pi_recon_settled'])
        ->and($reversal->kind)->toBe(ReversalKind::Refund)
        ->and($reversal->provider_reference)->toBe('re_console_1')
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1)
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded);

    Event::assertDispatchedTimes(OrderRefunded::class, 1);
});

it('finds a Stripe dispute whose notification was lost in the race with the settlement', function (): void {
    // #235: Stripe sent `charge.dispute.created` one second before
    // `checkout.session.completed`, when the payment carried no payment
    // intent yet — the endpoint acknowledged it with 200 and Stripe never
    // repeated it. The settled pass asks the API instead of waiting.
    [$order, $payment] = reconciliationTestStripeSettled();

    $stripe = new ReconciliationTestStripeApi;
    $stripe->reversals = [
        'disputes' => [[
            'id' => 'dp_recon_1',
            'status' => 'lost',
            'amount' => 11900,
            'currency' => 'eur',
            'reason' => 'fraudulent',
            'fee' => 1500,
            'created' => now()->subDay()->getTimestamp(),
        ]],
        'refunds' => [],
    ];
    app()->instance(StripeApi::class, $stripe);

    Event::fake([PaymentReversed::class]);

    $this->artisan('commerce:reconcile-payments --settled')
        ->expectsOutputToContain('1 reversal(s) found')
        ->assertSuccessful();

    $reversal = PaymentReversal::withoutBrandScope()->sole();

    expect($stripe->reversalFetches)->toBe(['pi_recon_settled'])
        ->and($reversal->kind)->toBe(ReversalKind::Dispute)
        ->and($reversal->status)->toBe(ReversalStatus::Final)
        ->and($reversal->provider_reference)->toBe('dp_recon_1')
        ->and((string) $reversal->amount)->toBe('119.00')
        ->and((string) $reversal->fee_amount)->toBe('15.00')
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Reversed)
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReversed);

    Event::assertDispatched(PaymentReversed::class);
});

it('records the same Stripe dispute once however often the sweep runs', function (): void {
    // The unique `(provider, provider_reference)` key of ADR-0057 point 1:
    // a daily sweep re-reads the same dispute for as long as the payment
    // stays in the window, and must report it as news exactly once.
    [$order] = reconciliationTestStripeSettled();

    $stripe = new ReconciliationTestStripeApi;
    $stripe->reversals = [
        'disputes' => [[
            'id' => 'dp_recon_twice',
            'status' => 'lost',
            'amount' => 11900,
            'currency' => 'eur',
            'reason' => null,
            'fee' => null,
            'created' => now()->subDay()->getTimestamp(),
        ]],
        'refunds' => [],
    ];
    app()->instance(StripeApi::class, $stripe);

    Event::fake([PaymentReversed::class]);

    $this->artisan('commerce:reconcile-payments --settled')->assertSuccessful();
    $this->artisan('commerce:reconcile-payments --settled')->assertSuccessful();

    expect(PaymentReversal::withoutBrandScope()->count())->toBe(1)
        ->and($stripe->reversalFetches)->toBe(['pi_recon_settled', 'pi_recon_settled'])
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReversed);

    // The reversal is news exactly once: the second run converges on the
    // row the first one wrote and announces nothing.
    Event::assertDispatchedTimes(PaymentReversed::class, 1);
});

it('leaves a Stripe dispute the provider has not decided open', function (): void {
    // An open dispute is money withheld, not money gone (ADR-0057 point 1):
    // the row and the operator notice exist, the receivable does not revive.
    [$order, $payment] = reconciliationTestStripeSettled();

    $stripe = new ReconciliationTestStripeApi;
    $stripe->reversals = [
        'disputes' => [[
            'id' => 'dp_recon_open',
            'status' => 'needs_response',
            'amount' => 11900,
            'currency' => 'eur',
            'reason' => 'fraudulent',
            'fee' => null,
            'created' => now()->subDay()->getTimestamp(),
        ]],
        'refunds' => [],
    ];
    app()->instance(StripeApi::class, $stripe);

    $this->artisan('commerce:reconcile-payments --settled')->assertSuccessful();

    expect(PaymentReversal::withoutBrandScope()->sole()->status)->toBe(ReversalStatus::Open)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
});

/**
 * A settled Stripe payment carrying an **open** dispute row, the state
 * `charge.dispute.created` leaves behind (#249). Built by letting the sweep
 * itself record it, so the row is exactly the one the webhook would have
 * written rather than a factory's idea of it.
 *
 * @return array{0: Order, 1: Payment, 2: ReconciliationTestStripeApi}
 */
function reconciliationTestOpenDispute(string $reference = 'dp_recon_closing'): array
{
    [$order, $payment] = reconciliationTestStripeSettled();

    $stripe = new ReconciliationTestStripeApi;
    $stripe->reversals = [
        'disputes' => [[
            'id' => $reference,
            'status' => 'needs_response',
            'amount' => 11900,
            'currency' => 'eur',
            'reason' => 'fraudulent',
            'fee' => null,
            'created' => now()->subDays(20)->getTimestamp(),
        ]],
        'refunds' => [],
    ];
    app()->instance(StripeApi::class, $stripe);

    test()->artisan('commerce:reconcile-payments --settled')->assertSuccessful();

    expect(PaymentReversal::withoutBrandScope()->sole()->status)->toBe(ReversalStatus::Open);

    return [$order, $payment, $stripe];
}

it('closes an open reversal when the sweep finds the dispute was won (#249)', function (): void {
    // The `charge.dispute.closed` never arrived — the endpoint was
    // unreachable (#232) — so before this fix the row stayed `Open` and the
    // case sat in the operator's work list for ever. `won` withdraws it,
    // which is what the webhook would have done.
    [$order, $payment, $stripe] = reconciliationTestOpenDispute();

    $stripe->reversals['disputes'][0]['status'] = 'won';

    Event::fake([PaymentReversed::class]);

    $this->artisan('commerce:reconcile-payments --settled')->assertSuccessful();

    $reversal = PaymentReversal::withoutBrandScope()->sole();

    expect($reversal->status)->toBe(ReversalStatus::Withdrawn)
        ->and($reversal->finalized_at)->not->toBeNull()
        // The money stayed: nothing about the receivable moves.
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    Event::assertNotDispatched(PaymentReversed::class);

    // A second run changes nothing: a decided reversal is terminal.
    $this->artisan('commerce:reconcile-payments --settled')->assertSuccessful();

    expect(PaymentReversal::withoutBrandScope()->count())->toBe(1)
        ->and(PaymentReversal::withoutBrandScope()->sole()->status)->toBe(ReversalStatus::Withdrawn)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('closes an open reversal and revives the receivable when the sweep finds the dispute was lost (#249)', function (): void {
    // The other direction, and the one that moves money: exactly what
    // `charge.dispute.closed` with status `lost` would have produced —
    // `RecordPaymentReversal`, one Action, one outcome (ADR-0057 point 7).
    [$order, $payment] = reconciliationTestOpenDispute('dp_recon_lost_late');

    /** @var ReconciliationTestStripeApi $stripe */
    $stripe = app(StripeApi::class);
    $stripe->reversals['disputes'][0]['status'] = 'lost';

    Event::fake([PaymentReversed::class]);

    $this->artisan('commerce:reconcile-payments --settled')->assertSuccessful();

    $reversal = PaymentReversal::withoutBrandScope()->sole();

    expect($reversal->status)->toBe(ReversalStatus::Final)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Reversed)
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReversed)
        // The deadline counts from the moment the sweep learned of the
        // closure, never from the day the dispute was opened twenty days
        // ago — otherwise `commerce:cancel-unpaid-reversals` would cancel
        // the order on its next run and the payer would get no days at all.
        ->and($reversal->repay_due_on->isFuture())->toBeTrue();

    Event::assertDispatchedTimes(PaymentReversed::class, 1);

    $this->artisan('commerce:reconcile-payments --settled')->assertSuccessful();

    expect(PaymentReversal::withoutBrandScope()->count())->toBe(1)
        ->and(PaymentReversal::withoutBrandScope()->sole()->status)->toBe(ReversalStatus::Final)
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReversed);

    Event::assertDispatchedTimes(PaymentReversed::class, 1);
});

it('closes an open reversal when an early fraud warning was closed (#249)', function (): void {
    // `warning_closed` decides nothing about the money, but it does decide
    // the dispute — and a row left `Open` for a warning Stripe has closed is
    // the same permanent work-list entry.
    [$order, $payment] = reconciliationTestOpenDispute('dp_recon_warning');

    /** @var ReconciliationTestStripeApi $stripe */
    $stripe = app(StripeApi::class);
    $stripe->reversals['disputes'][0]['status'] = 'warning_closed';

    $this->artisan('commerce:reconcile-payments --settled')->assertSuccessful();

    expect(PaymentReversal::withoutBrandScope()->sole()->status)->toBe(ReversalStatus::Withdrawn)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('writes no row for a won dispute this deployment never heard of (#249)', function (): void {
    // Unchanged from #235, and the reason `closes()` needs a row to close:
    // a withdrawn row for a dispute nobody here ever saw would tell the
    // operator about a decision that cost nothing.
    reconciliationTestStripeSettled();

    $stripe = new ReconciliationTestStripeApi;
    $stripe->reversals = [
        'disputes' => [[
            'id' => 'dp_recon_never_heard',
            'status' => 'won',
            'amount' => 11900,
            'currency' => 'eur',
            'reason' => null,
            'fee' => null,
            'created' => now()->subDays(10)->getTimestamp(),
        ]],
        'refunds' => [],
    ];
    app()->instance(StripeApi::class, $stripe);

    $this->artisan('commerce:reconcile-payments --settled')
        ->expectsOutputToContain('0 reversal(s) found')
        ->assertSuccessful();

    expect(PaymentReversal::withoutBrandScope()->count())->toBe(0);
});

it('settles a pending Unzer payment whose Paypage was paid after the notification was lost (#191)', function (): void {
    // Unzer notifies per *account*, so a notification lost between Unzer and
    // this deployment is lost for every payment on that account at once —
    // and until this the hourly pass skipped the provider entirely.
    $this->withBrand();

    $account = SettlementAccount::factory()->create([
        'provider' => SettlementAccount::PROVIDER_UNZER,
        'provider_account_id' => null,
        'credentials' => ['private_key' => 's-priv-pending'],
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'total_gross' => '45.00',
        'currency' => 'EUR',
    ]);

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'settlement_account_id' => $account->id,
        'provider' => SettlementAccount::PROVIDER_UNZER,
        // The Paypage id, which is *not* what the lookup uses.
        'external_id' => 'ppg_recon_pending',
        'amount' => '45.00',
        'currency' => 'EUR',
    ]);

    reconciliationTestAge($payment, 60);

    $unzer = new ReconciliationTestUnzerApi;
    $unzer->pendingPaymentId = 's-pay-recon-pending';
    $unzer->pendingState = 'completed';
    app()->instance(UnzerApi::class, $unzer);

    Event::fake([OrderPaid::class]);

    $this->artisan('commerce:reconcile-payments --pending')
        ->expectsOutputToContain('1 corrected')
        ->assertSuccessful();

    // Asked by our own `Payment` ULID, the `orderId` the Paypage carries —
    // never by the Paypage id, which addresses no payment.
    expect($unzer->orderIdFetches)->toBe([$payment->id])
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        // Stamped for the eventual refund, which cancels the *payment*.
        ->and($payment->provider_transaction_id)->toBe('s-pay-recon-pending')
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    Event::assertDispatchedTimes(OrderPaid::class, 1);

    // A second run changes nothing, and does not even ask: the payment has
    // left `pending`, so it is no longer a candidate at all. (Output is not
    // asserted here — only the first `artisan()` call of a test captures
    // it.)
    $this->artisan('commerce:reconcile-payments --pending')->assertSuccessful();

    expect($unzer->orderIdFetches)->toBe([$payment->id])
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('fails a pending Unzer payment the payer cancelled at the Paypage (#191)', function (): void {
    $this->withBrand();

    $account = SettlementAccount::factory()->create([
        'provider' => SettlementAccount::PROVIDER_UNZER,
        'provider_account_id' => null,
        'credentials' => ['private_key' => 's-priv-cancel'],
    ]);

    $order = Order::factory()->create(['status' => OrderStatus::Pending, 'total_gross' => '45.00', 'currency' => 'EUR']);

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'settlement_account_id' => $account->id,
        'provider' => SettlementAccount::PROVIDER_UNZER,
        'external_id' => 'ppg_recon_cancelled',
        'amount' => '45.00',
        'currency' => 'EUR',
    ]);

    reconciliationTestAge($payment, 60);

    $unzer = new ReconciliationTestUnzerApi;
    $unzer->pendingPaymentId = 's-pay-recon-cancelled';
    $unzer->pendingState = 'canceled';
    app()->instance(UnzerApi::class, $unzer);

    $this->artisan('commerce:reconcile-payments --pending')->assertSuccessful();

    // The order stays pending: a failed attempt settles nothing and a new
    // checkout may follow (`RecordPaymentFailure`).
    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending)
        // D-0.8.2-4: the release valves of #231 count from here, not from
        // the day the order was placed (#249).
        ->and($order->first_failed_attempt_at)->not->toBeNull();
});

it('leaves a pending Unzer payment alone while Unzer holds no payment for it (#191)', function (): void {
    // A Paypage that was opened and never paid: Unzer answers
    // `API.310.100.003` and the seam turns that into null. Nothing decided,
    // and the expiry sweeps own that order's clock.
    $this->withBrand();

    $account = SettlementAccount::factory()->create([
        'provider' => SettlementAccount::PROVIDER_UNZER,
        'provider_account_id' => null,
        'credentials' => ['private_key' => 's-priv-unpaid'],
    ]);

    $order = Order::factory()->create(['status' => OrderStatus::Pending, 'total_gross' => '45.00', 'currency' => 'EUR']);

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'settlement_account_id' => $account->id,
        'provider' => SettlementAccount::PROVIDER_UNZER,
        'external_id' => 'ppg_recon_unpaid',
        'amount' => '45.00',
        'currency' => 'EUR',
    ]);

    reconciliationTestAge($payment, 60);

    $unzer = new ReconciliationTestUnzerApi;
    $unzer->pendingPaymentId = null;
    app()->instance(UnzerApi::class, $unzer);

    $this->artisan('commerce:reconcile-payments --pending')
        ->expectsOutputToContain('0 corrected')
        ->assertSuccessful();

    expect($unzer->orderIdFetches)->toBe([$payment->id])
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);
});

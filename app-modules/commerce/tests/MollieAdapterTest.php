<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Mollie\MollieApi;
use Konventix\Commerce\Settlement\Mollie\MolliePaymentSnapshot;
use Konventix\Commerce\Settlement\Mollie\MollieSettlementAdapter;

/**
 * Mollie adapter + fetch-back webhook (ADR-0039) against a fake of the
 * `MollieApi` seam — no SDK call leaves the process.
 */
final class MollieAdapterTestFakeApi implements MollieApi
{
    /** @var list<array{params: array<string, mixed>, apiKey: string}> */
    public array $created = [];

    /** @var list<array{paymentId: string, params: array<string, mixed>, apiKey: string}> */
    public array $refunds = [];

    /** @var list<array{paymentId: string, apiKey: string}> */
    public array $fetches = [];

    public string $fetchStatus = 'paid';

    public function createPayment(array $params, string $apiKey): MolliePaymentSnapshot
    {
        $this->created[] = ['params' => $params, 'apiKey' => $apiKey];

        // Distinct per creation, like the real API — the payments table is
        // unique per (provider, external_id).
        return new MolliePaymentSnapshot(
            id: 'tr_fake_'.count($this->created),
            status: 'open',
            checkoutUrl: 'https://www.mollie.test/checkout/select-method/fake',
        );
    }

    public function fetchPayment(string $paymentId, string $apiKey): MolliePaymentSnapshot
    {
        $this->fetches[] = ['paymentId' => $paymentId, 'apiKey' => $apiKey];

        return new MolliePaymentSnapshot(id: $paymentId, status: $this->fetchStatus);
    }

    public function refundPayment(string $paymentId, array $params, string $apiKey): void
    {
        $this->refunds[] = ['paymentId' => $paymentId, 'params' => $params, 'apiKey' => $apiKey];
    }
}

function mollieAdapterTestSetup(): array
{
    test()->withBrand();

    $account = SettlementAccount::factory()->create([
        'provider' => SettlementAccount::PROVIDER_MOLLIE,
        'provider_account_id' => null,
        'credentials' => ['api_key' => 'live_fake_key'],
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);

    $fake = new MollieAdapterTestFakeApi;
    app()->instance(MollieApi::class, $fake);

    return [$account, $order, $fake];
}

it('starts a hosted checkout with the brand key and our payment ULID in the webhook URL', function (): void {
    [$account, $order, $fake] = mollieAdapterTestSetup();

    $redirect = app(MollieSettlementAdapter::class)->startCheckout($account, $order, 'https://brand.test/return');

    expect($redirect->url)->toBe('https://www.mollie.test/checkout/select-method/fake');

    $payment = Payment::withoutBrandScope()->findOrFail($redirect->paymentId);
    expect($payment->external_id)->toBe('tr_fake_1')
        ->and($payment->status)->toBe(PaymentStatus::Pending);

    expect($fake->created)->toHaveCount(1);
    ['params' => $params, 'apiKey' => $apiKey] = $fake->created[0];

    // The brand's own key, decrypted just-in-time (ADR-0007/ADR-0039).
    expect($apiKey)->toBe('live_fake_key')
        ->and($params['amount'])->toBe(['currency' => 'EUR', 'value' => '119.00'])
        ->and($params['redirectUrl'])->toBe('https://brand.test/return')
        ->and($params['webhookUrl'])->toContain('/api/commerce/webhooks/mollie/'.$payment->id);
});

it('refuses to start a checkout on an account without a Mollie key', function (): void {
    [, $order] = mollieAdapterTestSetup();

    $keyless = SettlementAccount::factory()->disconnected()->create([
        'provider' => SettlementAccount::PROVIDER_MOLLIE,
        'credentials' => null,
    ]);

    expect(fn () => app(MollieSettlementAdapter::class)->startCheckout($keyless, $order, 'https://brand.test/return'))
        ->toThrow(RuntimeException::class, 'not a connected Mollie account');
});

it('settles the order when the fetched status is paid', function (): void {
    [, $order, $fake] = mollieAdapterTestSetup();

    $redirect = app(MollieSettlementAdapter::class)->startCheckout(
        SettlementAccount::withoutBrandScope()->firstOrFail(),
        $order,
        'https://brand.test/return',
    );

    Event::fake([OrderPaid::class]);

    test()->post('/api/commerce/webhooks/mollie/'.$redirect->paymentId, ['id' => 'tr_fake_1'])
        ->assertOk();

    // Fetch-back: the stored Mollie id is read, the posted body ignored.
    expect($fake->fetches)->toHaveCount(1)
        ->and($fake->fetches[0]['paymentId'])->toBe('tr_fake_1')
        ->and(Payment::withoutBrandScope()->findOrFail($redirect->paymentId)->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    Event::assertDispatched(OrderPaid::class, fn (OrderPaid $e): bool => $e->settlementMethod === 'mollie');
});

it('follows the fetched truth, not a forged body', function (): void {
    [, $order, $fake] = mollieAdapterTestSetup();

    $redirect = app(MollieSettlementAdapter::class)->startCheckout(
        SettlementAccount::withoutBrandScope()->firstOrFail(),
        $order,
        'https://brand.test/return',
    );

    // The attacker posts a foreign id; the handler still reads tr_fake_1,
    // whose real status is not yet decided — nothing may settle.
    $fake->fetchStatus = 'open';

    test()->post('/api/commerce/webhooks/mollie/'.$redirect->paymentId, ['id' => 'tr_paid_elsewhere'])
        ->assertOk();

    expect($fake->fetches[0]['paymentId'])->toBe('tr_fake_1')
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);
});

it('marks failure and expiry from the fetched status', function (): void {
    [, $order, $fake] = mollieAdapterTestSetup();
    $adapter = app(MollieSettlementAdapter::class);
    $account = SettlementAccount::withoutBrandScope()->firstOrFail();

    Event::fake([PaymentFailed::class]);

    $failed = $adapter->startCheckout($account, $order, 'https://brand.test/return');
    $fake->fetchStatus = 'failed';
    test()->post('/api/commerce/webhooks/mollie/'.$failed->paymentId)->assertOk();

    expect(Payment::withoutBrandScope()->findOrFail($failed->paymentId)->status)->toBe(PaymentStatus::Failed);

    $expired = $adapter->startCheckout($account, $order, 'https://brand.test/return');
    $fake->fetchStatus = 'expired';
    test()->post('/api/commerce/webhooks/mollie/'.$expired->paymentId)->assertOk();

    expect(Payment::withoutBrandScope()->findOrFail($expired->paymentId)->status)->toBe(PaymentStatus::Expired);

    Event::assertDispatchedTimes(PaymentFailed::class, 2);
});

it('acknowledges unknown payments without touching the API', function (): void {
    [, , $fake] = mollieAdapterTestSetup();

    test()->post('/api/commerce/webhooks/mollie/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertOk();

    expect($fake->fetches)->toBe([]);
});

it('refunds through the brand key against the settled Mollie payment', function (): void {
    [$account, $order, $fake] = mollieAdapterTestSetup();

    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_MOLLIE,
        'settlement_account_id' => $account->id,
        'external_id' => 'tr_fake_1',
        'provider_transaction_id' => 'tr_fake_1',
        'amount' => '119.00',
        'currency' => 'EUR',
    ]);

    app(MollieSettlementAdapter::class)->refund($account->id, $order, '119.00', 'EUR');

    expect($fake->refunds)->toHaveCount(1)
        ->and($fake->refunds[0]['paymentId'])->toBe('tr_fake_1')
        ->and($fake->refunds[0]['params']['amount'])->toBe(['currency' => 'EUR', 'value' => '119.00'])
        ->and($fake->refunds[0]['apiKey'])->toBe('live_fake_key');
});

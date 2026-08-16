<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Unzer\UnzerApi;
use Konventix\Commerce\Settlement\Unzer\UnzerPaymentSnapshot;
use Konventix\Commerce\Settlement\Unzer\UnzerPaypageSnapshot;
use Konventix\Commerce\Settlement\Unzer\UnzerSettlementAdapter;

/**
 * Unzer adapter + account-level fetch-back webhook (ADR-0039) against a
 * fake of the `UnzerApi` seam — no SDK call leaves the process.
 */
final class UnzerAdapterTestFakeApi implements UnzerApi
{
    /** @var list<array{amount: float, currency: string, orderId: string, returnUrl: string, privateKey: string}> */
    public array $paypages = [];

    /** @var list<array{paymentId: string, privateKey: string}> */
    public array $fetches = [];

    /** @var list<array{paymentId: string, amount: float, privateKey: string}> */
    public array $refunds = [];

    public string $fetchState = 'completed';

    public ?string $fetchOrderId = null;

    public function createPaypage(
        float $amount,
        string $currency,
        string $orderId,
        string $returnUrl,
        string $privateKey,
    ): UnzerPaypageSnapshot {
        $this->paypages[] = [
            'amount' => $amount, 'currency' => $currency, 'orderId' => $orderId,
            'returnUrl' => $returnUrl, 'privateKey' => $privateKey,
        ];

        return new UnzerPaypageSnapshot(
            id: 'ppg_fake_'.count($this->paypages),
            redirectUrl: 'https://paypage.unzer.test/pay/fake',
        );
    }

    public function fetchPayment(string $paymentId, string $privateKey): UnzerPaymentSnapshot
    {
        $this->fetches[] = ['paymentId' => $paymentId, 'privateKey' => $privateKey];

        return new UnzerPaymentSnapshot(id: $paymentId, state: $this->fetchState, orderId: $this->fetchOrderId);
    }

    public function refundPayment(string $paymentId, float $amount, string $privateKey): void
    {
        $this->refunds[] = ['paymentId' => $paymentId, 'amount' => $amount, 'privateKey' => $privateKey];
    }
}

function unzerAdapterTestSetup(): array
{
    test()->withBrand();

    $account = SettlementAccount::factory()->create([
        'provider' => SettlementAccount::PROVIDER_UNZER,
        'provider_account_id' => null,
        'credentials' => ['private_key' => 's-priv-fake'],
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);

    $fake = new UnzerAdapterTestFakeApi;
    app()->instance(UnzerApi::class, $fake);

    return [$account, $order, $fake];
}

it('starts a Paypage checkout stamped with our payment ULID as orderId', function (): void {
    [$account, $order, $fake] = unzerAdapterTestSetup();

    $redirect = app(UnzerSettlementAdapter::class)->startCheckout($account, $order, 'https://brand.test/return');

    expect($redirect->url)->toBe('https://paypage.unzer.test/pay/fake');

    $payment = Payment::withoutBrandScope()->findOrFail($redirect->paymentId);
    expect($payment->external_id)->toBe('ppg_fake_1')
        ->and($payment->status)->toBe(PaymentStatus::Pending);

    expect($fake->paypages)->toHaveCount(1)
        ->and($fake->paypages[0]['amount'])->toBe(119.00)
        ->and($fake->paypages[0]['currency'])->toBe('EUR')
        // The correlation key an account-level webhook reads back.
        ->and($fake->paypages[0]['orderId'])->toBe($payment->id)
        ->and($fake->paypages[0]['privateKey'])->toBe('s-priv-fake');
});

it('refuses to start a checkout on an account without an Unzer key', function (): void {
    [, $order] = unzerAdapterTestSetup();

    $keyless = SettlementAccount::factory()->disconnected()->create([
        'provider' => SettlementAccount::PROVIDER_UNZER,
        'credentials' => null,
    ]);

    expect(fn () => app(UnzerSettlementAdapter::class)->startCheckout($keyless, $order, 'https://brand.test/return'))
        ->toThrow(RuntimeException::class, 'not a connected Unzer account');
});

it('settles via the account webhook when the fetched payment completed', function (): void {
    [$account, $order, $fake] = unzerAdapterTestSetup();

    $redirect = app(UnzerSettlementAdapter::class)->startCheckout($account, $order, 'https://brand.test/return');

    $fake->fetchState = 'completed';
    $fake->fetchOrderId = $redirect->paymentId;

    Event::fake([OrderPaid::class]);

    test()->post('/api/commerce/webhooks/unzer/'.$account->id, ['paymentId' => 's-pay-1'])
        ->assertOk();

    $payment = Payment::withoutBrandScope()->findOrFail($redirect->paymentId);
    expect($payment->status)->toBe(PaymentStatus::Succeeded)
        // The refundable money identifier is the payment, not the Paypage.
        ->and($payment->provider_transaction_id)->toBe('s-pay-1')
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    Event::assertDispatched(OrderPaid::class, fn (OrderPaid $e): bool => $e->settlementMethod === 'unzer');
});

it('marks the payment failed when the fetched payment is canceled', function (): void {
    [$account, $order, $fake] = unzerAdapterTestSetup();

    $redirect = app(UnzerSettlementAdapter::class)->startCheckout($account, $order, 'https://brand.test/return');

    $fake->fetchState = 'canceled';
    $fake->fetchOrderId = $redirect->paymentId;

    test()->post('/api/commerce/webhooks/unzer/'.$account->id, ['paymentId' => 's-pay-1'])
        ->assertOk();

    expect(Payment::withoutBrandScope()->findOrFail($redirect->paymentId)->status)->toBe(PaymentStatus::Failed)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);
});

it('decides nothing from a pending fetch or a foreign orderId', function (): void {
    [$account, $order, $fake] = unzerAdapterTestSetup();

    $redirect = app(UnzerSettlementAdapter::class)->startCheckout($account, $order, 'https://brand.test/return');

    // Still pending on Unzer's side: no transition.
    $fake->fetchState = 'pending';
    $fake->fetchOrderId = $redirect->paymentId;
    test()->post('/api/commerce/webhooks/unzer/'.$account->id, ['paymentId' => 's-pay-1'])->assertOk();

    expect(Payment::withoutBrandScope()->findOrFail($redirect->paymentId)->status)->toBe(PaymentStatus::Pending);

    // Completed, but for an orderId that is not ours: no transition either.
    $fake->fetchState = 'completed';
    $fake->fetchOrderId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    test()->post('/api/commerce/webhooks/unzer/'.$account->id, ['paymentId' => 's-pay-2'])->assertOk();

    expect(Payment::withoutBrandScope()->findOrFail($redirect->paymentId)->status)->toBe(PaymentStatus::Pending)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);
});

it('acknowledges an unknown account without touching the API', function (): void {
    [, , $fake] = unzerAdapterTestSetup();

    test()->post('/api/commerce/webhooks/unzer/01ARZ3NDEKTSV4RRFFQ69G5FAV', ['paymentId' => 's-pay-1'])
        ->assertOk();

    expect($fake->fetches)->toBe([]);
});

it('refunds through the brand key against the recorded Unzer payment', function (): void {
    [$account, $order, $fake] = unzerAdapterTestSetup();

    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_UNZER,
        'settlement_account_id' => $account->id,
        'external_id' => 'ppg_fake_1',
        'provider_transaction_id' => 's-pay-9',
        'amount' => '119.00',
        'currency' => 'EUR',
    ]);

    app(UnzerSettlementAdapter::class)->refund($account->id, $order, '119.00', 'EUR');

    expect($fake->refunds)->toHaveCount(1)
        ->and($fake->refunds[0]['paymentId'])->toBe('s-pay-9')
        ->and($fake->refunds[0]['amount'])->toBe(119.00)
        ->and($fake->refunds[0]['privateKey'])->toBe('s-priv-fake');
});

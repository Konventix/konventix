<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\OrderRefunded;
use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Events\PaymentReversed;
use Konventix\Commerce\Exceptions\ProviderRefusedRefundException;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Mollie\MollieApi;
use Konventix\Commerce\Settlement\Mollie\MolliePaymentSnapshot;
use Konventix\Commerce\Settlement\Mollie\MollieSettlementAdapter;
use Mollie\Api\Exceptions\MollieException;

/**
 * Mollie adapter + fetch-back webhook (ADR-0039) against a fake of the
 * `MollieApi` seam — no SDK call leaves the process.
 */
/**
 * `MollieException` is abstract and `ApiException` needs a whole PSR response
 * to build — this is the smallest concrete member of the hierarchy the
 * adapter is asked to translate (#236 fix round 1).
 */
final class MollieAdapterTestRefusal extends MollieException {}

final class MollieAdapterTestFakeApi implements MollieApi
{
    /** @var list<array{params: array<string, mixed>, apiKey: string}> */
    public array $created = [];

    /** @var list<array{paymentId: string, params: array<string, mixed>, apiKey: string}> */
    public array $refunds = [];

    /** @var list<array{paymentId: string, apiKey: string}> */
    public array $fetches = [];

    public string $fetchStatus = 'paid';

    public ?string $fetchAmountChargedBack = null;

    public ?string $fetchAmountRefunded = null;

    /** @var list<array{id: string, amount: string, reason: ?string, createdAt: string}> */
    public array $fetchChargebacks = [];

    /** @var list<array{id: string, amount: string, reason: ?string, createdAt: string, status: string}> */
    public array $fetchRefunds = [];

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

        return new MolliePaymentSnapshot(
            id: $paymentId,
            status: $this->fetchStatus,
            amountChargedBack: $this->fetchAmountChargedBack,
            amountRefunded: $this->fetchAmountRefunded,
            chargebacks: $this->fetchChargebacks,
            refunds: $this->fetchRefunds,
        );
    }

    public bool $refuseRefund = false;

    public function refundPayment(string $paymentId, array $params, string $apiKey): string
    {
        if ($this->refuseRefund) {
            throw new MollieAdapterTestRefusal('The payment is already fully refunded.');
        }

        $this->refunds[] = ['paymentId' => $paymentId, 'params' => $params, 'apiKey' => $apiKey];

        return 're_mollie_fake';
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

/**
 * #236 fix round 1, review finding 5: `Mollie\Api\Exceptions\ApiException`
 * extends `Exception`, so a refund Mollie refuses used to leave the adapter
 * untranslated and reach the console as a 500 — the same defect the Stripe
 * path was filed for. Every adapter now speaks one Commerce exception.
 */
it('translates a refusal from the Mollie SDK into a provider refusal (#236)', function (): void {
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

    $fake->refuseRefund = true;

    expect(fn () => app(MollieSettlementAdapter::class)->refund($account->id, $order, '119.00', 'EUR'))
        ->toThrow(ProviderRefusedRefundException::class, 'already fully refunded');
});

/**
 * The reversal half of the same fetch-back (ADR-0057 point 7): the
 * notification for a *paid* Mollie payment no longer returns early — the
 * chargeback and refund lists are read from the same snapshot.
 */
it('records a Mollie chargeback on a settled payment', function (): void {
    [, $order, $fake] = mollieAdapterTestSetup();

    $redirect = app(MollieSettlementAdapter::class)->startCheckout(
        SettlementAccount::withoutBrandScope()->firstOrFail(),
        $order,
        'https://brand.test/return',
    );

    test()->post('/api/commerce/webhooks/mollie/'.$redirect->paymentId)->assertOk();

    $fake->fetchChargebacks = [[
        'id' => 'chb_test_1',
        'amount' => '119.00',
        'reason' => 'AC04',
        'createdAt' => '2026-09-07T09:00:00+00:00',
    ]];
    $fake->fetchAmountChargedBack = '119.00';

    Event::fake([PaymentReversed::class]);

    test()->post('/api/commerce/webhooks/mollie/'.$redirect->paymentId)->assertOk();

    $reversal = PaymentReversal::withoutBrandScope()->sole();

    expect($reversal->kind)->toBe(ReversalKind::Return)
        ->and($reversal->status)->toBe(ReversalStatus::Final)
        ->and($reversal->provider_reference)->toBe('chb_test_1')
        ->and($reversal->reason_code)->toBe('AC04')
        ->and((string) $reversal->amount)->toBe('119.00')
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReversed)
        ->and(Payment::withoutBrandScope()->findOrFail($redirect->paymentId)->status)
        ->toBe(PaymentStatus::Reversed);

    Event::assertDispatched(PaymentReversed::class, fn (PaymentReversed $e): bool => $e->kind === 'return');

    // A replayed notification re-reads the same chargeback and converges.
    test()->post('/api/commerce/webhooks/mollie/'.$redirect->paymentId)->assertOk();

    expect(PaymentReversal::withoutBrandScope()->count())->toBe(1);
});

it('records a Mollie refund we did not make as an external refund', function (): void {
    [, $order, $fake] = mollieAdapterTestSetup();

    $redirect = app(MollieSettlementAdapter::class)->startCheckout(
        SettlementAccount::withoutBrandScope()->firstOrFail(),
        $order,
        'https://brand.test/return',
    );

    test()->post('/api/commerce/webhooks/mollie/'.$redirect->paymentId)->assertOk();

    $fake->fetchRefunds = [[
        'id' => 're_test_1',
        'amount' => '119.00',
        'reason' => 'operator refunded in the dashboard',
        'createdAt' => '2026-09-07T09:00:00+00:00',
        'status' => 'refunded',
    ]];
    $fake->fetchAmountRefunded = '119.00';

    Event::fake([OrderRefunded::class]);

    test()->post('/api/commerce/webhooks/mollie/'.$redirect->paymentId)->assertOk();

    expect(PaymentReversal::withoutBrandScope()->sole()->kind)->toBe(ReversalKind::ExternalRefund)
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded);

    Event::assertDispatched(OrderRefunded::class, fn (OrderRefunded $e): bool => $e->whole === true);
});

it('ignores a Mollie refund that never reached the payer', function (): void {
    // Fix round 1, finding 4. A queued or failed refund is not money that
    // moved; recording it would set the order to refunded and issue a
    // credit note for a refund the bank never made.
    [, $order, $fake] = mollieAdapterTestSetup();

    $redirect = app(MollieSettlementAdapter::class)->startCheckout(
        SettlementAccount::withoutBrandScope()->firstOrFail(),
        $order,
        'https://brand.test/return',
    );

    test()->post('/api/commerce/webhooks/mollie/'.$redirect->paymentId)->assertOk();

    $fake->fetchRefunds = [[
        'id' => 're_test_failed',
        'amount' => '119.00',
        'reason' => null,
        'createdAt' => '2026-09-07T09:00:00+00:00',
        'status' => 'failed',
    ]];

    Event::fake([OrderRefunded::class]);

    test()->post('/api/commerce/webhooks/mollie/'.$redirect->paymentId)->assertOk();

    expect(PaymentReversal::withoutBrandScope()->count())->toBe(0)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    Event::assertNotDispatched(OrderRefunded::class);
});

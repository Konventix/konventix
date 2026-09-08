<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Enums\HostedPaymentState;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeCheckoutSession;
use Konventix\Commerce\Settlement\Stripe\StripeSettlementAdapter;
use Konventix\Platform\Models\Brand;
use Stripe\Exception\InvalidRequestException;

/**
 * The adapter's decisions against a fake of the `StripeApi` seam
 * (ADR-0039) — no SDK call leaves the process. Every call is made on this
 * instance's own Stripe account, so no call carries an account id
 * (ADR-0058).
 */
final class StripeAdapterTestFakeApi implements StripeApi
{
    /** @var list<array<string, mixed>> */
    public array $sessions = [];

    /** @var list<array<string, mixed>> */
    public array $refunds = [];

    public function createCheckoutSession(array $params): StripeCheckoutSession
    {
        $this->sessions[] = $params;

        return new StripeCheckoutSession(id: 'cs_test_fake', url: 'https://checkout.stripe.test/pay/cs_test_fake');
    }

    /**
     * Nothing took this payment's money back (#235). Every fake here answers
     * the empty pair unless its own test says otherwise.
     *
     * @return array{disputes: list<array{id: string, status: string, amount: int, currency: string, reason: ?string, fee: ?int, created: ?int}>, refunds: list<array{id: string, amount: int, currency: string, reason: ?string, charge: ?string, created: ?int}>}
     */
    public function fetchPaymentIntentReversals(string $paymentIntentId): array
    {
        return ['disputes' => [], 'refunds' => []];
    }

    public function createRefund(array $params): string
    {
        $this->refunds[] = $params;

        return 're_stripe_fake';
    }

    /**
     * What Stripe says the session is doing. `hostedPaymentState()` reads it
     * (#231), so the tests set it.
     *
     * @var array{status: string, payment_status: string, payment_intent: ?string, payment_intent_status: ?string}
     */
    public array $session = [
        'status' => 'open',
        'payment_status' => 'unpaid',
        'payment_intent' => null,
        'payment_intent_status' => null,
    ];

    /**
     * @return array{status: string, payment_status: string, payment_intent: ?string, payment_intent_status: ?string}
     */
    public function fetchCheckoutSession(string $sessionId): array
    {
        return $this->session;
    }
}

/**
 * Refuses the first session the way Stripe refuses an over-long combined
 * statement descriptor, and accepts whatever comes next.
 */
final class StripeAdapterTestRefusesDescriptorApi implements StripeApi
{
    /** @var list<array<string, mixed>> */
    public array $sessions = [];

    public string $stripeParam = 'payment_intent_data[statement_descriptor_suffix]';

    public string $message = 'The combined length of your statement descriptor and suffix must be at most 22 characters.';

    public function createCheckoutSession(array $params): StripeCheckoutSession
    {
        $this->sessions[] = $params;

        if (count($this->sessions) === 1) {
            throw InvalidRequestException::factory(
                message: $this->message,
                httpStatus: 400,
                stripeParam: $this->stripeParam,
            );
        }

        return new StripeCheckoutSession(id: 'cs_test_fake', url: 'https://checkout.stripe.test/pay/cs_test_fake');
    }

    /**
     * Nothing took this payment's money back (#235). Every fake here answers
     * the empty pair unless its own test says otherwise.
     *
     * @return array{disputes: list<array{id: string, status: string, amount: int, currency: string, reason: ?string, fee: ?int, created: ?int}>, refunds: list<array{id: string, amount: int, currency: string, reason: ?string, charge: ?string, created: ?int}>}
     */
    public function fetchPaymentIntentReversals(string $paymentIntentId): array
    {
        return ['disputes' => [], 'refunds' => []];
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
        return [
            'status' => 'open',
            'payment_status' => 'unpaid',
            'payment_intent' => null,
            'payment_intent_status' => null,
        ];
    }
}

function stripeAdapterTestSetup(?Brand $brand = null): array
{
    $brand ??= test()->withBrand();

    $account = SettlementAccount::factory()->create([
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'statement_descriptor_suffix' => 'SXLAN',
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'total_gross' => '154.70',
        'currency' => 'EUR',
    ]);

    OrderLine::factory()->create([
        'order_id' => $order->id,
        'position' => 1,
        'label' => 'Exhibitor package',
        'line_gross' => '119.00',
    ]);
    OrderLine::factory()->create([
        'order_id' => $order->id,
        'position' => 2,
        'label' => 'Crew badge',
        'line_gross' => '35.70',
    ]);

    $fake = new StripeAdapterTestFakeApi;
    app()->instance(StripeApi::class, $fake);

    return [$account, $order, $fake, $brand];
}

it('starts a hosted checkout with a pending payment and the snapshotted line amounts', function (): void {
    $brand = $this->withBrand();
    [$account, $order, $fake] = stripeAdapterTestSetup($brand);

    $redirect = app(StripeSettlementAdapter::class)->startCheckout(
        $account,
        $order,
        'https://brand.test/pay/order/'.$order->id.'/return',
    );

    expect($redirect->url)->toBe('https://checkout.stripe.test/pay/cs_test_fake');

    $payment = Payment::withoutBrandScope()->findOrFail($redirect->paymentId);
    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->external_id)->toBe('cs_test_fake')
        ->and($payment->provider)->toBe('stripe')
        ->and($payment->amount)->toBe('154.70');

    expect($fake->sessions)->toHaveCount(1);
    $params = $fake->sessions[0];

    expect($params['mode'])->toBe('payment')
        ->and($params['client_reference_id'])->toBe($payment->id)
        // Tax is computed by the domain, per line, half-up (ADR-0030):
        // Stripe gets finished gross amounts and is never asked to tax.
        ->and($params['line_items'])->toHaveCount(2)
        ->and($params['line_items'][0]['price_data']['unit_amount'])->toBe(11900)
        ->and($params['line_items'][1]['price_data']['unit_amount'])->toBe(3570)
        // ADR-0007 forbids platform fees from ticket revenue; dynamic
        // payment methods stay enabled by omission.
        ->and($params)->not->toHaveKey('application_fee_amount')
        ->and($params)->not->toHaveKey('payment_method_types');
});

it('names the brand and the operator on the session and on the payment intent', function (): void {
    // ADR-0058 point 4: one account, many brands — the split is metadata,
    // carried onto the intent so it survives onto the charge, the balance
    // transaction and every Stripe export.
    $brand = $this->withBrand();
    [$account, $order, $fake] = stripeAdapterTestSetup($brand);

    app(StripeSettlementAdapter::class)->startCheckout($account, $order, 'https://brand.test/return');

    $params = $fake->sessions[0];
    $payment = Payment::withoutBrandScope()->where('order_id', $order->id)->sole();

    $expected = [
        'konventix_payment_id' => $payment->id,
        'konventix_order_id' => $order->id,
        'konventix_brand_id' => $brand->id,
        'konventix_brand_name' => $brand->name,
        'konventix_operator_id' => $brand->operator_id,
    ];

    expect($params['metadata'])->toBe($expected)
        ->and($params['payment_intent_data']['metadata'])->toBe($expected)
        // What the payer reads on their card statement names the brand.
        ->and($params['payment_intent_data']['statement_descriptor_suffix'])->toBe('SXLAN')
        // Plain words for the Stripe rows that show no metadata.
        ->and($params['payment_intent_data']['description'])->toContain($brand->name);
});

it('sends no statement descriptor suffix when the brand set none', function (): void {
    $brand = $this->withBrand();
    [$account, $order, $fake] = stripeAdapterTestSetup($brand);

    $account->forceFill(['statement_descriptor_suffix' => null])->save();

    app(StripeSettlementAdapter::class)->startCheckout($account, $order, 'https://brand.test/return');

    expect($fake->sessions[0]['payment_intent_data'])->not->toHaveKey('statement_descriptor_suffix');
});

it('starts the checkout anyway when Stripe refuses the statement descriptor', function (): void {
    // The suffix is cosmetic; the payment is not. If Stripe refuses the
    // combined descriptor — its account prefix is unreadable from here since
    // ADR-0058 removed `fetchAccount()`, so the save-time rule can only be
    // conservative — the payer must still reach a payment page rather than a
    // 500, with the descriptor falling back to the account's own default.
    $brand = $this->withBrand();
    [$account, $order] = stripeAdapterTestSetup($brand);

    $fake = new StripeAdapterTestRefusesDescriptorApi;
    app()->instance(StripeApi::class, $fake);

    Log::spy();

    $redirect = app(StripeSettlementAdapter::class)->startCheckout($account, $order, 'https://brand.test/return');

    expect($redirect->url)->toBe('https://checkout.stripe.test/pay/cs_test_fake')
        ->and($fake->sessions)->toHaveCount(2)
        ->and($fake->sessions[0]['payment_intent_data'])->toHaveKey('statement_descriptor_suffix')
        ->and($fake->sessions[1]['payment_intent_data'])->not->toHaveKey('statement_descriptor_suffix')
        // Everything else about the retry is the first attempt unchanged.
        ->and($fake->sessions[1]['metadata'])->toBe($fake->sessions[0]['metadata'])
        ->and($fake->sessions[1]['client_reference_id'])->toBe($fake->sessions[0]['client_reference_id']);

    Log::shouldHaveReceived('warning')->once();

    expect(Payment::withoutBrandScope()->findOrFail($redirect->paymentId)->external_id)->toBe('cs_test_fake');
});

it('lets any other Stripe rejection surface instead of retrying blindly', function (): void {
    $brand = $this->withBrand();
    [$account, $order] = stripeAdapterTestSetup($brand);

    $fake = new StripeAdapterTestRefusesDescriptorApi;
    $fake->stripeParam = 'line_items';
    $fake->message = 'No such price.';
    app()->instance(StripeApi::class, $fake);

    expect(fn () => app(StripeSettlementAdapter::class)->startCheckout($account, $order, 'https://brand.test/return'))
        ->toThrow(InvalidRequestException::class);

    expect($fake->sessions)->toHaveCount(1);
});

it('refuses to start a checkout on a non-Stripe account', function (): void {
    [, $order] = stripeAdapterTestSetup();

    $manual = SettlementAccount::factory()->disconnected()->create();

    expect(fn () => app(StripeSettlementAdapter::class)->startCheckout($manual, $order, 'https://brand.test/return'))
        ->toThrow(RuntimeException::class, 'is not a Stripe account');
});

it('refunds against the payment intent recorded by the webhook', function (): void {
    [$account, $order, $fake] = stripeAdapterTestSetup();

    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'settlement_account_id' => $account->id,
        'external_id' => 'cs_test_settled',
        'provider_transaction_id' => 'pi_test_789',
        'amount' => '154.70',
        'currency' => 'EUR',
    ]);

    app(StripeSettlementAdapter::class)->refund($account->id, $order, '154.70', 'EUR');

    // Direct on this instance's own account (ADR-0058): no account id.
    expect($fake->refunds)->toHaveCount(1)
        ->and($fake->refunds[0]['payment_intent'])->toBe('pi_test_789')
        ->and($fake->refunds[0]['amount'])->toBe(15470);
});

it('refuses to refund without a recorded payment intent', function (): void {
    [$account, $order] = stripeAdapterTestSetup();

    expect(fn () => app(StripeSettlementAdapter::class)->refund($account->id, $order, '154.70', 'EUR'))
        ->toThrow(RuntimeException::class, 'no refundable Stripe payment');
});

/**
 * Stripe refusing to answer at all (#231): every question is a failure, and
 * the adapter must read that as "we do not know", never as "not paid".
 */
final class StripeAdapterTestUnreachableApi implements StripeApi
{
    public function createCheckoutSession(array $params): StripeCheckoutSession
    {
        throw new RuntimeException('Stripe is unreachable');
    }

    public function createRefund(array $params): string
    {
        throw new RuntimeException('Stripe is unreachable');
    }

    /**
     * @return array{disputes: list<array{id: string, status: string, amount: int, currency: string, reason: ?string, fee: ?int, created: ?int}>, refunds: list<array{id: string, amount: int, currency: string, reason: ?string, charge: ?string, created: ?int}>}
     */
    public function fetchPaymentIntentReversals(string $paymentIntentId): array
    {
        throw new RuntimeException('Stripe is unreachable');
    }

    /**
     * @return array{status: string, payment_status: string, payment_intent: ?string, payment_intent_status: ?string}
     */
    public function fetchCheckoutSession(string $sessionId): array
    {
        throw new RuntimeException('Stripe is unreachable');
    }
}

/**
 * #231 fix round 1, review finding 1: `unpaid` is not "not paid". A
 * delayed-notification method (SEPA debit, bank transfer) completes the
 * session while the money is still travelling, and calling that `NotPaid`
 * has the sweep cancel the order and mail the buyer mid-transfer — the
 * failure #231 exists to prevent. Only an intent that has given up is a
 * cancellation, which is the rule `ReconcilePayments` already applies to the
 * same seam and Mollie's adapter to `pending`/`authorized`.
 */
it('answers the sweep about a hosted Stripe session', function (
    string $status,
    string $paymentStatus,
    ?string $intentStatus,
    HostedPaymentState $expected,
): void {
    [, $order, $fake] = stripeAdapterTestSetup();

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'external_id' => 'cs_test_pending',
        'amount' => '154.70',
        'currency' => 'EUR',
    ]);

    $fake->session = [
        'status' => $status,
        'payment_status' => $paymentStatus,
        'payment_intent' => 'pi_test_state',
        'payment_intent_status' => $intentStatus,
    ];

    expect(app(StripeSettlementAdapter::class)->hostedPaymentState($payment))->toBe($expected);
})->with([
    'paid' => ['complete', 'paid', 'succeeded', HostedPaymentState::Paid],
    'nothing to pay' => ['complete', 'no_payment_required', null, HostedPaymentState::Paid],
    'SEPA still travelling' => ['complete', 'unpaid', 'processing', HostedPaymentState::InFlight],
    'waiting on the payer' => ['complete', 'unpaid', 'requires_action', HostedPaymentState::InFlight],
    'intent not expanded' => ['complete', 'unpaid', null, HostedPaymentState::InFlight],
    'refused' => ['complete', 'unpaid', 'requires_payment_method', HostedPaymentState::NotPaid],
    'given up' => ['complete', 'unpaid', 'canceled', HostedPaymentState::NotPaid],
    'never finished' => ['open', 'unpaid', null, HostedPaymentState::NotPaid],
    'session expired' => ['expired', 'unpaid', null, HostedPaymentState::NotPaid],
]);

it('answers the sweep Unreachable when Stripe cannot be reached at all (#231)', function (): void {
    [, $order] = stripeAdapterTestSetup();

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'external_id' => 'cs_test_pending',
        'amount' => '154.70',
        'currency' => 'EUR',
    ]);

    app()->instance(StripeApi::class, new StripeAdapterTestUnreachableApi);

    expect(app(StripeSettlementAdapter::class)->hostedPaymentState($payment))
        ->toBe(HostedPaymentState::Unreachable);
});

it('has nothing to ask about a payment that never reached a hosted page (#231)', function (): void {
    [, $order] = stripeAdapterTestSetup();

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'external_id' => null,
        'amount' => '154.70',
        'currency' => 'EUR',
    ]);

    expect(app(StripeSettlementAdapter::class)->hostedPaymentState($payment))
        ->toBe(HostedPaymentState::Unanswerable);
});

it('never marks a Stripe order paid outside the webhook path', function (): void {
    [$account, $order] = stripeAdapterTestSetup();

    expect(fn () => app(StripeSettlementAdapter::class)->markOrderPaid($account->id, $order, '154.70', 'EUR', 'stripe'))
        ->toThrow(LogicException::class);
});

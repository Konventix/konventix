<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\OrderRefunded;
use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Events\PaymentReceived;
use Konventix\Commerce\Events\PaymentReversed;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use Konventix\Commerce\Models\SettlementAccount;

/**
 * The Stripe webhook endpoint (ADR-0039): signature verification is real —
 * the tests compute the same HMAC Stripe would — and settlement truth only
 * ever moves through it.
 */
const STRIPE_WEBHOOK_TEST_SECRET = 'whsec_test_secret';

function stripeWebhookTestPending(): array
{
    test()->withBrand();
    config(['services.stripe.webhook_secret' => STRIPE_WEBHOOK_TEST_SECRET]);

    $order = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'external_id' => 'cs_test_abc',
        'amount' => '119.00',
        'currency' => 'EUR',
    ]);

    return [$order, $payment];
}

/**
 * @param  array<string, mixed>  $session
 */
function stripeWebhookTestPost(string $type, array $session, ?string $secret = STRIPE_WEBHOOK_TEST_SECRET): TestResponse
{
    $payload = json_encode([
        'id' => 'evt_test_1',
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => ['object' => 'checkout.session', ...$session]],
    ], JSON_THROW_ON_ERROR);

    $timestamp = time();
    $signature = $secret === null
        ? 'garbage'
        : hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

    return test()->call(
        'POST',
        '/api/commerce/webhooks/stripe',
        server: [
            'HTTP_STRIPE_SIGNATURE' => sprintf('t=%d,v1=%s', $timestamp, $signature),
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $payload,
    );
}

it('settles the order on a completed session that is paid', function (): void {
    [$order, $payment] = stripeWebhookTestPending();

    Event::fake([PaymentReceived::class, OrderPaid::class]);

    stripeWebhookTestPost('checkout.session.completed', [
        'id' => 'cs_test_abc',
        'payment_status' => 'paid',
        'payment_intent' => 'pi_test_1',
    ])->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->provider_transaction_id)->toBe('pi_test_1')
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    Event::assertDispatched(OrderPaid::class, fn (OrderPaid $e): bool => $e->settlementMethod === 'stripe');
});

it('does not settle on a completed session that is still unpaid', function (): void {
    // Delayed-notification methods: `completed` arrives while unpaid; the
    // decision comes later as async_payment_succeeded/failed.
    [$order, $payment] = stripeWebhookTestPending();

    Event::fake([OrderPaid::class]);

    stripeWebhookTestPost('checkout.session.completed', [
        'id' => 'cs_test_abc',
        'payment_status' => 'unpaid',
        'payment_intent' => 'pi_test_1',
    ])->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        // The money identifier is still recorded for the eventual refund.
        ->and($payment->provider_transaction_id)->toBe('pi_test_1')
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);

    Event::assertNotDispatched(OrderPaid::class);
});

it('settles on the async success that follows an unpaid completion', function (): void {
    [$order, $payment] = stripeWebhookTestPending();

    stripeWebhookTestPost('checkout.session.completed', [
        'id' => 'cs_test_abc', 'payment_status' => 'unpaid', 'payment_intent' => 'pi_test_1',
    ])->assertOk();

    stripeWebhookTestPost('checkout.session.async_payment_succeeded', [
        'id' => 'cs_test_abc', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_1',
    ])->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('marks the payment failed on async failure and leaves the order pending', function (): void {
    [$order, $payment] = stripeWebhookTestPending();

    Event::fake([PaymentFailed::class]);

    stripeWebhookTestPost('checkout.session.async_payment_failed', [
        'id' => 'cs_test_abc', 'payment_status' => 'unpaid',
    ])->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);

    Event::assertDispatched(PaymentFailed::class);
});

it('marks an expired session as expired', function (): void {
    [, $payment] = stripeWebhookTestPending();

    stripeWebhookTestPost('checkout.session.expired', [
        'id' => 'cs_test_abc', 'payment_status' => 'unpaid',
    ])->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Expired);
});

it('rejects a payload whose signature does not verify', function (): void {
    [$order, $payment] = stripeWebhookTestPending();

    stripeWebhookTestPost('checkout.session.completed', [
        'id' => 'cs_test_abc', 'payment_status' => 'paid',
    ], secret: null)->assertStatus(400);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);
});

it('acknowledges sessions and event types it does not know', function (): void {
    // Non-2xx makes Stripe retry for days; "not ours" is not fixed by retry.
    stripeWebhookTestPending();

    stripeWebhookTestPost('checkout.session.completed', [
        'id' => 'cs_unknown', 'payment_status' => 'paid',
    ])->assertOk();

    stripeWebhookTestPost('payment_intent.created', ['id' => 'pi_x'])->assertOk();
});

it('converges when Stripe replays the success notification', function (): void {
    [, $payment] = stripeWebhookTestPending();

    Event::fake([OrderPaid::class, PaymentReceived::class]);

    foreach (range(1, 3) as $attempt) {
        stripeWebhookTestPost('checkout.session.completed', [
            'id' => 'cs_test_abc', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_1',
        ])->assertOk();
    }

    Event::assertDispatchedTimes(OrderPaid::class, 1);
    Event::assertDispatchedTimes(PaymentReceived::class, 1);
    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded);
});

/**
 * The reversal half of the same endpoint (ADR-0057 point 7): Stripe maps
 * from the signed event, correlated through `provider_transaction_id` —
 * the payment intent recorded at settlement.
 */
function stripeWebhookTestSettled(string $settlesType = 'registration'): array
{
    test()->withBrand();
    config(['services.stripe.webhook_secret' => STRIPE_WEBHOOK_TEST_SECRET]);

    $order = Order::factory()->paid()->create([
        'settles_type' => $settlesType,
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'external_id' => 'cs_test_settled',
        'provider_transaction_id' => 'pi_test_settled',
        'amount' => '119.00',
        'currency' => 'EUR',
    ]);

    return [$order, $payment];
}

/**
 * @param  array<string, mixed>  $object
 */
function stripeWebhookTestPostObject(string $type, string $objectName, array $object): TestResponse
{
    $payload = json_encode([
        'id' => 'evt_test_2',
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => ['object' => $objectName, ...$object]],
    ], JSON_THROW_ON_ERROR);

    $timestamp = time();

    return test()->call(
        'POST',
        '/api/commerce/webhooks/stripe',
        server: [
            'HTTP_STRIPE_SIGNATURE' => sprintf(
                't=%d,v1=%s',
                $timestamp,
                hash_hmac('sha256', $timestamp.'.'.$payload, STRIPE_WEBHOOK_TEST_SECRET),
            ),
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $payload,
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function stripeWebhookTestDispute(string $status, array $overrides = []): array
{
    return [
        'id' => 'dp_test_1',
        'amount' => 11900,
        'currency' => 'eur',
        'charge' => 'ch_test_1',
        'payment_intent' => 'pi_test_settled',
        'reason' => 'insufficient_funds',
        'status' => $status,
        'created' => Carbon::parse('2026-09-07 09:00:00')->getTimestamp(),
        'balance_transactions' => [['fee' => 1500]],
        ...$overrides,
    ];
}

it('revives the receivable on a dispute that is born lost', function (): void {
    // Stripe's shape for a SEPA debit return: no evidence can be filed, so
    // the dispute closes as it opens (ADR-0057 point 1).
    [$order, $payment] = stripeWebhookTestSettled();

    Event::fake([PaymentReversed::class]);

    stripeWebhookTestPostObject('charge.dispute.created', 'dispute', stripeWebhookTestDispute('lost'))
        ->assertOk();

    $reversal = PaymentReversal::withoutBrandScope()->sole();

    expect($reversal->kind)->toBe(ReversalKind::Return)
        ->and($reversal->status)->toBe(ReversalStatus::Final)
        ->and((string) $reversal->amount)->toBe('119.00')
        ->and((string) $reversal->fee_amount)->toBe('15.00')
        ->and($reversal->reason_code)->toBe('insufficient_funds')
        ->and($reversal->repay_due_on?->toDateString())->toBe('2026-09-14')
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Reversed)
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReversed);

    Event::assertDispatched(
        PaymentReversed::class,
        fn (PaymentReversed $e): bool => $e->orderId === $order->id
            && $e->kind === 'return'
            && $e->amount === '119.00'
            && $e->providerReference === 'dp_test_1'
            && $e->repayDueOn === '2026-09-14',
    );
});

it('holds an opened dispute inside the module until Stripe decides', function (): void {
    [$order, $payment] = stripeWebhookTestSettled();

    Event::fake([PaymentReversed::class]);

    stripeWebhookTestPostObject('charge.dispute.created', 'dispute', stripeWebhookTestDispute('needs_response'))
        ->assertOk();

    $reversal = PaymentReversal::withoutBrandScope()->sole();

    expect($reversal->kind)->toBe(ReversalKind::Dispute)
        ->and($reversal->status)->toBe(ReversalStatus::Open)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    Event::assertNotDispatched(PaymentReversed::class);
});

it('withdraws the reversal when the organizer wins the dispute', function (): void {
    [$order, $payment] = stripeWebhookTestSettled();

    Event::fake([PaymentReversed::class]);

    stripeWebhookTestPostObject('charge.dispute.created', 'dispute', stripeWebhookTestDispute('under_review'))
        ->assertOk();
    stripeWebhookTestPostObject('charge.dispute.closed', 'dispute', stripeWebhookTestDispute('won'))
        ->assertOk();

    expect(PaymentReversal::withoutBrandScope()->sole()->status)->toBe(ReversalStatus::Withdrawn)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    Event::assertNotDispatched(PaymentReversed::class);
});

it('reverses the payment when the organizer loses the dispute', function (): void {
    [$order, $payment] = stripeWebhookTestSettled();

    Event::fake([PaymentReversed::class]);

    stripeWebhookTestPostObject('charge.dispute.created', 'dispute', stripeWebhookTestDispute('under_review'))
        ->assertOk();
    stripeWebhookTestPostObject('charge.dispute.closed', 'dispute', stripeWebhookTestDispute('lost'))
        ->assertOk();

    expect(PaymentReversal::withoutBrandScope()->count())->toBe(1)
        ->and(PaymentReversal::withoutBrandScope()->sole()->status)->toBe(ReversalStatus::Final)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Reversed)
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReversed);

    Event::assertDispatchedTimes(PaymentReversed::class, 1);
});

it('records a refund made in the Stripe dashboard as an external refund', function (): void {
    [$order] = stripeWebhookTestSettled();
    Invoice::factory()->create(['order_id' => $order->id]);

    Event::fake([OrderRefunded::class]);

    stripeWebhookTestPostObject('charge.refunded', 'charge', [
        'id' => 'ch_test_1',
        'payment_intent' => 'pi_test_settled',
        'amount' => 11900,
        'amount_refunded' => 11900,
        'currency' => 'eur',
        'created' => Carbon::parse('2026-09-07 09:00:00')->getTimestamp(),
        'refunds' => ['object' => 'list', 'data' => [['id' => 're_test_1', 'reason' => 'requested_by_customer']]],
    ])->assertOk();

    $reversal = PaymentReversal::withoutBrandScope()->sole();

    expect($reversal->kind)->toBe(ReversalKind::ExternalRefund)
        ->and($reversal->provider_reference)->toBe('re_test_1')
        ->and((string) $reversal->amount)->toBe('119.00')
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1);

    Event::assertDispatched(OrderRefunded::class, fn (OrderRefunded $e): bool => $e->whole === true);
});

it('ignores a charge refund that our own RefundOrder already explains', function (): void {
    [$order] = stripeWebhookTestSettled();
    $order->forceFill(['status' => OrderStatus::Refunded])->save();

    Event::fake([OrderRefunded::class]);

    stripeWebhookTestPostObject('charge.refunded', 'charge', [
        'id' => 'ch_test_1',
        'payment_intent' => 'pi_test_settled',
        'amount' => 11900,
        'amount_refunded' => 11900,
        'currency' => 'eur',
        'refunds' => ['object' => 'list', 'data' => [['id' => 're_test_1']]],
    ])->assertOk();

    expect(PaymentReversal::withoutBrandScope()->count())->toBe(0);

    Event::assertNotDispatched(OrderRefunded::class);
});

it('acknowledges a dispute for a payment intent it does not know', function (): void {
    stripeWebhookTestSettled();

    stripeWebhookTestPostObject('charge.dispute.created', 'dispute', stripeWebhookTestDispute('lost', [
        'payment_intent' => 'pi_somebody_else',
        'charge' => 'ch_somebody_else',
    ]))->assertOk();

    expect(PaymentReversal::withoutBrandScope()->count())->toBe(0);
});

it('converges when Stripe replays the dispute notification', function (): void {
    [$order] = stripeWebhookTestSettled();

    Event::fake([PaymentReversed::class]);

    foreach (range(1, 3) as $attempt) {
        stripeWebhookTestPostObject('charge.dispute.created', 'dispute', stripeWebhookTestDispute('lost'))
            ->assertOk();
    }

    expect(PaymentReversal::withoutBrandScope()->count())->toBe(1)
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReversed);

    Event::assertDispatchedTimes(PaymentReversed::class, 1);
});

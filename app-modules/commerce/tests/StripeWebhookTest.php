<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Events\PaymentReceived;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
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

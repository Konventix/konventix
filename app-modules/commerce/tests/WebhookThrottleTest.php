<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;

/**
 * #71: the PSP webhook routes carry a named rate limiter. Mollie and Unzer
 * answer every request with a provider call (ADR-0039's fetch-back), so a
 * flood of forged notifications is a cost on the organizer's own API quota,
 * not merely noise in the log — and `bootstrap/app.php` applies no `api`
 * throttler at all.
 *
 * The limit is generous on purpose: a real PSP burst (a busy sale settling,
 * a provider replaying a backlog) must never be turned away.
 */
const WEBHOOK_THROTTLE_TEST_SECRET = 'whsec_throttle_test';

/**
 * A deliberately unsigned POST: the throttle middleware runs before the
 * controller, so an invalid signature is the cheapest way to spend one
 * request against the limiter without touching a provider.
 */
function webhookThrottleTestUnsigned(): TestResponse
{
    return test()->call(
        'POST',
        '/api/commerce/webhooks/stripe',
        server: [
            'HTTP_STRIPE_SIGNATURE' => 't=1,v1=garbage',
            'CONTENT_TYPE' => 'application/json',
        ],
        content: '{}',
    );
}

it('carries the named webhook limiter on all three PSP routes', function (): void {
    $names = ['api.commerce.webhooks.stripe', 'api.commerce.webhooks.mollie', 'api.commerce.webhooks.unzer'];

    foreach ($names as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain('throttle:commerce-webhooks');
    }
});

it('answers the 121st webhook request in a minute with 429', function (): void {
    config(['services.stripe.webhook_secret' => WEBHOOK_THROTTLE_TEST_SECRET]);

    for ($i = 1; $i <= 120; $i++) {
        expect(webhookThrottleTestUnsigned()->getStatusCode())->toBe(400);
    }

    webhookThrottleTestUnsigned()->assertStatus(429);
});

it('leaves the signed settlement path unthrottled at ordinary volume', function (): void {
    $this->withBrand();
    config(['services.stripe.webhook_secret' => WEBHOOK_THROTTLE_TEST_SECRET]);

    $order = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'external_id' => 'cs_throttle_ok',
        'amount' => '119.00',
        'currency' => 'EUR',
    ]);

    $payload = json_encode([
        'id' => 'evt_throttle_1',
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'object' => 'checkout.session',
            'id' => 'cs_throttle_ok',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_throttle_ok',
        ]],
    ], JSON_THROW_ON_ERROR);

    $timestamp = time();

    test()->call(
        'POST',
        '/api/commerce/webhooks/stripe',
        server: [
            'HTTP_STRIPE_SIGNATURE' => sprintf(
                't=%d,v1=%s',
                $timestamp,
                hash_hmac('sha256', $timestamp.'.'.$payload, WEBHOOK_THROTTLE_TEST_SECRET),
            ),
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $payload,
    )->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded);
});

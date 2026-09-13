<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\CartExpired;
use Konventix\Commerce\Events\OrderCancelled;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\OrderPlaced;
use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Events\PaymentReceived;
use Konventix\Commerce\Events\PaymentReversalRecorded;
use Konventix\Commerce\Events\StockExhausted;
use Konventix\Commerce\Jobs\AdmitWaitingRoomBatch;
use Konventix\Commerce\Metrics\CommerceMetrics;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\WaitingRoom\AdmitFromWaitingRoom;
use Konventix\Commerce\WaitingRoom\WaitingRoomStore;
use Konventix\Platform\Models\Brand;
use Laravel\Pennant\Feature;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * Commerce's own numbers (ADR-0059, SPEC.md "Observability"): counters from
 * the module's events, gauges read at scrape time. Brands appear as slugs,
 * money only as aggregate cents per brand and currency, never a person.
 * GitLab #173, #111, #168.
 */
function commerceMetricsRendered(): string
{
    return (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());
}

function commerceMetricsScrape(): string
{
    config()->set('health.endpoint_secret', 'metrics-test-secret');

    return test()->withHeader('Authorization', 'Bearer metrics-test-secret')->get('/metrics')->assertOk()->getContent();
}

it('counts orders by outcome and paid cents by currency per brand slug', function (): void {
    $brand = $this->withBrand();

    event(new OrderPlaced('01ORDER', $brand->id, 'registration', '01REG', '10.00', '11.90', 'EUR', now()->toIso8601String()));
    event(new OrderPaid('01ORDER', $brand->id, 'registration', '01REG', '11.90', 'EUR', now()->toIso8601String(), 'stripe'));
    event(new OrderCancelled('01ORDER', $brand->id, 'registration', '01REG', null, 'operator', null, now()->toIso8601String()));

    $rendered = commerceMetricsRendered();

    expect($rendered)
        ->toContain('konventix_orders_total{brand="'.$brand->slug.'",outcome="placed"} 1')
        ->toContain('konventix_orders_total{brand="'.$brand->slug.'",outcome="paid"} 1')
        ->toContain('konventix_orders_total{brand="'.$brand->slug.'",outcome="cancelled"} 1')
        ->toContain('konventix_order_paid_amount_cents_total{brand="'.$brand->slug.'",currency="EUR"} 1190');
});

it('counts payments by provider and outcome', function (): void {
    $brand = $this->withBrand();

    event(new PaymentReceived('01PAY', '01ORDER', $brand->id, '11.90', 'EUR', 'stripe', 'pi_1', now()->toIso8601String()));
    event(new PaymentFailed('01PAY', '01ORDER', $brand->id, 'mollie', 'declined', now()->toIso8601String()));
    event(new PaymentReversalRecorded('01REV', '01PAY', '01ORDER', $brand->id, 'refund', 'settled', '11.90', 'EUR', 'stripe', 're_1', null, now()->toIso8601String()));

    expect(commerceMetricsRendered())
        ->toContain('konventix_payments_total{brand="'.$brand->slug.'",provider="stripe",outcome="received"} 1')
        ->toContain('konventix_payments_total{brand="'.$brand->slug.'",provider="mollie",outcome="failed"} 1')
        ->toContain('konventix_payments_total{brand="'.$brand->slug.'",provider="stripe",outcome="reversed"} 1');
});

it('counts expired carts and exhausted stock', function (): void {
    $brand = $this->withBrand();

    event(new CartExpired('01CART', $brand->id, '01USER', now()->toIso8601String()));
    event(new StockExhausted('01VAR', '01PROD', $brand->id, now()->toIso8601String()));

    expect(commerceMetricsRendered())
        ->toContain('konventix_carts_expired_total{brand="'.$brand->slug.'"} 1')
        ->toContain('konventix_stock_exhausted_total{brand="'.$brand->slug.'"} 1');
});

it('labels an unknown brand id as unknown rather than failing the event', function (): void {
    event(new StockExhausted('01VAR', '01PROD', '01NOSUCHBRAND', now()->toIso8601String()));

    expect(commerceMetricsRendered())->toContain('konventix_stock_exhausted_total{brand="unknown"} 1');
});

it('reports pending payments per brand at scrape time', function (): void {
    $brand = $this->withBrand();
    Order::factory()->count(2)->create(['brand_id' => $brand->id, 'status' => OrderStatus::Pending]);
    Order::factory()->create(['brand_id' => $brand->id, 'status' => OrderStatus::Paid]);

    expect(commerceMetricsScrape())->toContain('konventix_payments_pending{brand="'.$brand->slug.'"} 2');
});

it('reports zero pending payments for a brand once none are pending', function (): void {
    // The registry only ever sets the rows the closure hands it, and the
    // storage keeps a series until it is overwritten. A row emitted only
    // while something is pending outlives the condition: on dev a cancelled
    // test checkout left `payments_pending{brand="sxlan"} 1` standing for
    // two days against an empty table, and KonventixWebhookSilence with it
    // (2026-09-13). Two scrapes through one registry, as two scrapes of one
    // running instance share one Redis.
    $brand = $this->withBrand();
    $order = Order::factory()->create(['brand_id' => $brand->id, 'status' => OrderStatus::Pending]);

    expect(commerceMetricsScrape())->toContain('konventix_payments_pending{brand="'.$brand->slug.'"} 1');

    $order->update(['status' => OrderStatus::Cancelled]);

    expect(commerceMetricsScrape())->toContain('konventix_payments_pending{brand="'.$brand->slug.'"} 0');
});

it('reports every brand on the pending payments gauge, not only those with a pending order', function (): void {
    $quiet = $this->withBrand();
    $busy = Brand::factory()->create();
    Order::factory()->create(['brand_id' => $busy->id, 'status' => OrderStatus::Pending]);

    $scrape = commerceMetricsScrape();

    expect($scrape)
        ->toContain('konventix_payments_pending{brand="'.$quiet->slug.'"} 0')
        ->toContain('konventix_payments_pending{brand="'.$busy->slug.'"} 1');
});

it('reports waiting room depth, admissions and the last admission per brand', function (): void {
    $brand = $this->withBrand();
    Feature::for($brand)->activate('commerce:waiting-room');
    $store = app(WaitingRoomStore::class);
    $store->enqueue($brand->id, 'T1', '/shop', null);
    $store->enqueue($brand->id, 'T2', '/shop', null);

    (new AdmitWaitingRoomBatch($brand->id))->handle(app(AdmitFromWaitingRoom::class), $store);

    $scrape = commerceMetricsScrape();
    $slug = preg_quote($brand->slug, '/');

    // How many of the two the batch admits is the brand's setting, so the
    // assertions are on presence and sanity, not on an exact count.
    expect($scrape)
        ->toMatch('/konventix_waiting_room_depth\{brand="'.$slug.'"\} [012]\n/')
        ->toMatch('/konventix_waiting_room_admissions_total\{brand="'.$slug.'"\} [12]\n/')
        ->toMatch('/konventix_waiting_room_last_admission_timestamp_seconds\{brand="'.$slug.'"\} 1\d{9}/');
});

it('seeds the webhook last-received timestamp once and keeps a real one', function (): void {
    config()->set('services.stripe.secret', 'sk_test_configured');
    config()->set('services.stripe.webhook_secret', 'whsec_configured');
    $this->withBrand();
    SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_MOLLIE)->create();

    $first = commerceMetricsScrape();

    preg_match('/konventix_payment_webhook_last_received_timestamp_seconds\{provider="stripe"\} (\d+)/', $first, $m);
    expect($m)->toHaveCount(2);
    $seeded = (int) $m[1];

    Cache::forever(CommerceMetrics::webhookReceivedKey('stripe'), 1_800_000_000);
    $this->travel(1)->hours();

    $second = commerceMetricsScrape();

    expect($second)
        ->toContain('konventix_payment_webhook_last_received_timestamp_seconds{provider="stripe"} 1800000000')
        ->toContain('konventix_payment_webhook_last_received_timestamp_seconds{provider="mollie"} '.$seeded);
});

it('emits no webhook silence series at all when no provider is configured', function (): void {
    $this->withBrand();

    expect(commerceMetricsScrape())->not->toContain('konventix_payment_webhook_last_received_timestamp_seconds');
});

it('emits only the mollie row once a mollie settlement account exists', function (): void {
    $this->withBrand();
    SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_MOLLIE)->create();

    $scrape = commerceMetricsScrape();

    expect($scrape)
        ->toContain('konventix_payment_webhook_last_received_timestamp_seconds{provider="mollie"}')
        ->not->toContain('konventix_payment_webhook_last_received_timestamp_seconds{provider="stripe"}')
        ->not->toContain('konventix_payment_webhook_last_received_timestamp_seconds{provider="unzer"}');
});

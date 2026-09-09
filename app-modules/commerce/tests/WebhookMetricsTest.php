<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Konventix\Commerce\Http\Middleware\RecordWebhookMetrics;
use Konventix\Commerce\Metrics\CommerceMetrics;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * Every PSP webhook is counted by provider and outcome and timed; an
 * accepted one stamps the provider's last-received time, which the
 * KonventixWebhookSilence alert compares against pending payments
 * (ADR-0059, #168).
 */
function webhookMetricsRendered(): string
{
    return (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());
}

beforeEach(function (): void {
    Route::prefix('probe/webhooks')
        ->middleware(RecordWebhookMetrics::class)
        ->name('api.commerce.webhooks.')
        ->group(function (): void {
            Route::post('stripe', fn () => response()->json(['received' => true]))->name('stripe');
            Route::post('mollie', fn () => response()->json(['error' => 'bad'], 400))->name('mollie');
            Route::post('unzer', fn () => abort(500))->name('unzer');
        });
});

it('counts accepted, rejected and erroring webhooks per provider and times them', function (): void {
    $this->postJson('/probe/webhooks/stripe')->assertOk();
    $this->postJson('/probe/webhooks/mollie')->assertStatus(400);
    $this->postJson('/probe/webhooks/unzer')->assertStatus(500);

    $rendered = webhookMetricsRendered();

    expect($rendered)
        ->toContain('konventix_payment_webhooks_total{provider="stripe",outcome="accepted"} 1')
        ->toContain('konventix_payment_webhooks_total{provider="mollie",outcome="rejected"} 1')
        ->toContain('konventix_payment_webhooks_total{provider="unzer",outcome="error"} 1')
        ->toContain('konventix_payment_webhook_duration_seconds_count{provider="stripe"} 1');
});

it('stamps the last-received time only for an accepted webhook', function (): void {
    $this->travelTo(now()->setTimestamp(1_800_000_000));

    $this->postJson('/probe/webhooks/stripe')->assertOk();
    $this->postJson('/probe/webhooks/mollie')->assertStatus(400);

    expect(Cache::get(CommerceMetrics::webhookReceivedKey('stripe')))->toBe(1_800_000_000)
        ->and(Cache::get(CommerceMetrics::webhookReceivedKey('mollie')))->toBeNull();
});

it('is wired onto the real webhook routes', function (): void {
    $route = Route::getRoutes()->getByName('api.commerce.webhooks.stripe');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain(RecordWebhookMetrics::class);
});

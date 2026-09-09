<?php

declare(strict_types=1);

use App\Http\Middleware\RecordHttpMetrics;
use Illuminate\Support\Facades\Route;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * Every request is counted by method, status and route name; the duration
 * histogram carries a route label only for the pages #148 names thresholds
 * for, everything else is `other` (ADR-0059). GitLab #173, #96.
 */
function httpMetricsRendered(): string
{
    return (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());
}

beforeEach(function (): void {
    Route::get('/http-metrics-probe', fn (): array => ['ok' => true])->name('http.metrics.probe');
    Route::get('/http-metrics-listed', fn (): array => ['ok' => true])->name('commerce.shop.index');
    Route::get('/http-metrics-webhook', fn (): array => ['ok' => true])->name('api.commerce.webhooks.probe');
    Route::get('/http-metrics-boom', fn () => abort(503))->name('http.metrics.boom');
});

it('counts a request by method, status and route name', function (): void {
    $this->get('/http-metrics-probe')->assertOk();

    expect(httpMetricsRendered())
        ->toContain('konventix_http_requests_total{method="GET",status="200",route="http.metrics.probe"} 1');
});

it('counts an error status and an unnamed route', function (): void {
    $this->get('/http-metrics-boom')->assertStatus(503);
    $this->get('/no-such-page-anywhere')->assertNotFound();

    expect(httpMetricsRendered())
        ->toContain('konventix_http_requests_total{method="GET",status="503",route="http.metrics.boom"} 1')
        ->toContain('konventix_http_requests_total{method="GET",status="404",route="unnamed"} 1');
});

it('observes the duration under the route label only for allowlisted routes and prefixes', function (): void {
    $this->get('/http-metrics-probe')->assertOk();
    $this->get('/http-metrics-listed')->assertOk();
    $this->get('/http-metrics-webhook')->assertOk();

    expect(httpMetricsRendered())
        ->toContain('konventix_http_request_duration_seconds_count{route="other"} 1')
        ->toContain('konventix_http_request_duration_seconds_count{route="commerce.shop.index"} 1')
        ->toContain('konventix_http_request_duration_seconds_count{route="api.commerce.webhooks.probe"} 1');
});

it('leaves the diagnostics endpoints out of the counts', function (): void {
    config()->set('health.endpoint_secret', 'metrics-test-secret');

    $this->withHeader('Authorization', 'Bearer metrics-test-secret')->get('/metrics')->assertOk();
    $this->get('/up')->assertOk();

    expect(httpMetricsRendered())->not->toContain('route="prometheus.default"')
        ->and(httpMetricsRendered())->not->toContain('status="200",route="unnamed"');
});

it('maps names to histogram routes', function (): void {
    expect(RecordHttpMetrics::histogramRoute('commerce.cart.checkout'))->toBe('commerce.cart.checkout')
        ->and(RecordHttpMetrics::histogramRoute('api.commerce.webhooks.stripe'))->toBe('api.commerce.webhooks.stripe')
        ->and(RecordHttpMetrics::histogramRoute('admin.orders.index'))->toBe('other')
        ->and(RecordHttpMetrics::histogramRoute(null))->toBe('other');
});

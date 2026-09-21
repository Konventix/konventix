<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\MetricCatalog;
use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Support\Data\SupportContextData;
use Konventix\Support\Enums\SupportCategory;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Events\SupportRequestOpened;
use Konventix\Support\Events\SupportRequestStateChanged;
use Konventix\Support\Models\SupportRequest;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * ADR-0059, design §10.6. The counters are written by
 * `RecordSupportMetrics` from Support's own events; the two gauges are
 * answered by `SupportGauges` when `/metrics` is rendered.
 *
 * The name is prefixed because Pest helper names are process-global across
 * every module's test files (`.ai/rules/tests.md`).
 */
function supportMetricsRendered(): string
{
    return (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());
}

function supportMetricsScrape(object $test): string
{
    config()->set('health.endpoint_secret', 'support-metrics-secret');

    return $test->withHeader('Authorization', 'Bearer support-metrics-secret')
        ->get('/metrics')
        ->assertOk()
        ->getContent();
}

/**
 * One increment **per selected category**, not per report: a person who
 * ticks "purchase affected" beside "display" is telling the operator two
 * things, and `KonventixSupportPurchaseAffected` has to see the first
 * whatever else was ticked with it (D-SUP-06).
 */
it('counts a report once per selected category', function (): void {
    $brand = $this->withBrand();

    event(new SupportRequestOpened(
        requestId: (string) Str::ulid(),
        brandId: $brand->getKey(),
        userId: null,
        categories: [SupportCategory::PurchaseAffected->value, SupportCategory::Display->value],
        release: '0.7.0',
    ));

    $slug = BrandSlugs::of($brand->getKey());

    expect(supportMetricsRendered())
        ->toContain('konventix_support_requests_total{brand="'.$slug.'",category="purchase_affected"} 1')
        ->toContain('konventix_support_requests_total{brand="'.$slug.'",category="display"} 1');
});

/**
 * A console-filed report has no brand at all (ADR-0063), and a metric
 * without a brand label would be a metric nobody can group. `console` is
 * the stand-in, and no slug can collide with it.
 */
it('labels a console-filed report with the console stand-in', function (): void {
    event(new SupportRequestOpened(
        requestId: (string) Str::ulid(),
        brandId: null,
        userId: null,
        categories: [SupportCategory::Function->value],
        release: '0.7.0',
    ));

    expect(supportMetricsRendered())
        ->toContain('konventix_support_requests_total{brand="'.SupportContextData::CONSOLE_BRAND.'",category="function"} 1');
});

it('counts a state change under the state it moved into', function (): void {
    $brand = $this->withBrand();

    event(new SupportRequestStateChanged(
        requestId: (string) Str::ulid(),
        brandId: $brand->getKey(),
        from: 'seen',
        to: 'resolved',
        actorUserId: null,
    ));

    expect(supportMetricsRendered())
        ->toContain('konventix_support_request_transitions_total{brand="'.BrandSlugs::of($brand->getKey()).'",state="resolved"} 1');
});

/**
 * The lesson `CommerceGauges` paid for: the registry only ever *sets* the
 * series a closure returns, so a gauge that reports a brand only while it
 * has something waiting leaves the last non-zero value standing forever.
 */
it('reports every brand and the console on the open gauge, zero included', function (): void {
    $brand = $this->withBrand();
    SupportRequest::factory()->filedOnBrand($brand->getKey())->create();
    SupportRequest::factory()->inState(SupportRequestState::Resolved)->filedOnBrand($brand->getKey())->create();

    $scrape = supportMetricsScrape($this);

    expect($scrape)
        ->toContain('konventix_support_requests_open{brand="'.BrandSlugs::of($brand->getKey()).'"} 1')
        ->toContain('konventix_support_requests_open{brand="'.SupportContextData::CONSOLE_BRAND.'"} 0');
});

it('reports the age of the oldest unopened report, and zero when there is none', function (): void {
    expect(supportMetricsScrape($this))->toContain('konventix_support_requests_unseen_oldest_age_seconds 0');

    SupportRequest::factory()->create(['created_at' => now()->subHours(2)]);
    // Seen: it is no longer waiting to be opened, so it must not count.
    SupportRequest::factory()->inState(SupportRequestState::Seen)->create(['created_at' => now()->subDays(3)]);

    preg_match('/konventix_support_requests_unseen_oldest_age_seconds (\d+)/', supportMetricsScrape($this), $matches);

    expect((int) ($matches[1] ?? 0))->toBeGreaterThanOrEqual(7000)->toBeLessThan(7400);
});

it('declares every Support metric in the catalogue exactly once', function (): void {
    $declared = array_map(fn (MetricDefinition $d): string => $d->name, app(MetricCatalog::class)->all());
    $support = array_values(array_filter($declared, fn (string $name): bool => str_starts_with($name, 'support_')));

    expect($support)->toEqualCanonicalizing([
        'support_requests_total',
        'support_request_transitions_total',
        'support_requests_open',
        'support_requests_unseen_oldest_age_seconds',
    ]);
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Exceptions;
use Konventix\Platform\Observability\MetricCatalog;
use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;
use Konventix\Platform\Observability\Metrics;
use Konventix\Platform\Observability\UnknownMetric;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\Adapter;

/**
 * Counters and histograms are event-time writes into the shared registry;
 * the catalogue is the one list of what the application emits, and the
 * coverage test reads it (ADR-0059). GitLab #173.
 */
function renderedMetrics(): string
{
    return (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());
}

beforeEach(function (): void {
    app(MetricCatalog::class)->register(
        new MetricDefinition('probe_total', MetricKind::Counter, 'A probe.', ['colour', 'size']),
        new MetricDefinition('probe_seconds', MetricKind::Histogram, 'A probe.', ['colour'], [0.1, 1]),
        new MetricDefinition('probe_level', MetricKind::Gauge, 'A probe.'),
    );
});

it('increments a counter with labels in catalogue order whatever order the caller used', function (): void {
    app(Metrics::class)->increment('probe_total', ['size' => 'L', 'colour' => 'red']);
    app(Metrics::class)->increment('probe_total', ['colour' => 'red', 'size' => 'L'], 2);

    expect(renderedMetrics())->toContain('konventix_probe_total{colour="red",size="L"} 3');
});

it('observes a histogram', function (): void {
    app(Metrics::class)->observe('probe_seconds', 0.5, ['colour' => 'blue']);

    $rendered = renderedMetrics();

    expect($rendered)
        ->toContain('konventix_probe_seconds_bucket{colour="blue",le="0.1"} 0')
        ->toContain('konventix_probe_seconds_bucket{colour="blue",le="1"} 1')
        ->toContain('konventix_probe_seconds_count{colour="blue"} 1');
});

it('refuses a metric the catalogue does not know', function (): void {
    app(Metrics::class)->increment('nobody_declared_this_total');
})->throws(UnknownMetric::class);

it('refuses a label the catalogue did not declare', function (): void {
    app(Metrics::class)->increment('probe_total', ['colour' => 'red', 'size' => 'L', 'user_id' => 'x']);
})->throws(InvalidArgumentException::class, 'user_id');

it('refuses to increment a gauge or observe a counter', function (): void {
    expect(fn () => app(Metrics::class)->increment('probe_level'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(Metrics::class)->observe('probe_total', 1.0, ['colour' => 'red', 'size' => 'L']))->toThrow(InvalidArgumentException::class);
});

it('refuses a duplicate catalogue entry and a name with the namespace baked in', function (): void {
    expect(fn () => app(MetricCatalog::class)->register(new MetricDefinition('probe_total', MetricKind::Counter, 'Again.')))
        ->toThrow(InvalidArgumentException::class, 'probe_total')
        ->and(fn () => new MetricDefinition('konventix_x_total', MetricKind::Counter, 'Prefixed.'))
        ->toThrow(InvalidArgumentException::class, 'konventix_');
});

it('reports a registry storage failure instead of raising it into the caller', function (): void {
    Exceptions::fake();

    $brokenAdapter = new class implements Adapter
    {
        public function collect(): array
        {
            return [];
        }

        public function updateSummary(array $data): void
        {
            throw new RuntimeException('storage is down');
        }

        public function updateHistogram(array $data): void
        {
            throw new RuntimeException('storage is down');
        }

        public function updateGauge(array $data): void
        {
            throw new RuntimeException('storage is down');
        }

        public function updateCounter(array $data): void
        {
            throw new RuntimeException('storage is down');
        }

        public function wipeStorage(): void
        {
            throw new RuntimeException('storage is down');
        }
    };

    app()->instance(CollectorRegistry::class, new CollectorRegistry($brokenAdapter, false));

    app(Metrics::class)->increment('probe_total', ['colour' => 'red', 'size' => 'L']);

    Exceptions::assertReported(RuntimeException::class);
});

it('throttles storage-failure reporting to once per process per 60 seconds', function (): void {
    Metrics::forgetReportThrottle();
    Exceptions::fake();

    $brokenAdapter = new class implements Adapter
    {
        public function collect(): array
        {
            return [];
        }

        public function updateSummary(array $data): void
        {
            throw new RuntimeException('storage is down');
        }

        public function updateHistogram(array $data): void
        {
            throw new RuntimeException('storage is down');
        }

        public function updateGauge(array $data): void
        {
            throw new RuntimeException('storage is down');
        }

        public function updateCounter(array $data): void
        {
            throw new RuntimeException('storage is down');
        }

        public function wipeStorage(): void
        {
            throw new RuntimeException('storage is down');
        }
    };

    app()->instance(CollectorRegistry::class, new CollectorRegistry($brokenAdapter, false));

    app(Metrics::class)->increment('probe_total', ['colour' => 'red', 'size' => 'L']);
    app(Metrics::class)->increment('probe_total', ['colour' => 'red', 'size' => 'L']);

    Exceptions::assertReportedCount(1);

    Metrics::forgetReportThrottle();

    app(Metrics::class)->increment('probe_total', ['colour' => 'red', 'size' => 'L']);

    Exceptions::assertReportedCount(2);
});

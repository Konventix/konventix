<?php

declare(strict_types=1);

use App\Observability\MetricCatalog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Spatie\Health\Enums\Status;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults;

/**
 * The platform metrics the host answers for (ADR-0059): jobs processed and
 * failed per queue, the latest Spatie Health verdict per check as a number
 * vmalert can compare, the schedule heartbeat, and a count of log records
 * per level so "something logged an error" is an alert without a log-based
 * rule. GitLab #173, #168, #96.
 */
final class PlatformMetricsProbeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly bool $fail = false) {}

    public function handle(): void
    {
        if ($this->fail) {
            throw new RuntimeException('probe failed on purpose');
        }
    }
}

function platformMetricsRendered(): string
{
    return (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());
}

function platformMetricsScrape(): string
{
    config()->set('health.endpoint_secret', 'metrics-test-secret');

    return test()->withHeader('Authorization', 'Bearer metrics-test-secret')->get('/metrics')->assertOk()->getContent();
}

it('counts processed and failed jobs per queue', function (): void {
    PlatformMetricsProbeJob::dispatch()->onQueue('messaging');

    try {
        PlatformMetricsProbeJob::dispatch(fail: true)->onQueue('default');
    } catch (RuntimeException) {
        // the sync driver rethrows after raising JobFailed
    }

    expect(platformMetricsRendered())
        ->toContain('konventix_queue_jobs_processed_total{queue="messaging"} 1')
        ->toContain('konventix_queue_jobs_failed_total{queue="default",job_class="PlatformMetricsProbeJob"} 1');
});

it('counts log records of warning and above by level, and not below', function (): void {
    Log::warning('platform metrics probe');
    Log::error('platform metrics probe');
    Log::info('platform metrics probe');

    $rendered = platformMetricsRendered();

    expect($rendered)
        ->toContain('konventix_log_records_total{level="warning"} 1')
        ->toContain('konventix_log_records_total{level="error"} 1')
        ->not->toContain('level="info"');
});

it('renders the latest stored health result per check as a number', function (): void {
    $results = new StoredCheckResults(now(), collect([
        StoredCheckResult::make('Database')->status(Status::ok()->value),
        StoredCheckResult::make('Horizon')->status(Status::failed()->value),
        StoredCheckResult::make('UsedDiskSpace')->status(Status::warning()->value),
    ]));
    $this->mock(ResultStore::class)->shouldReceive('latestResults')->andReturn($results);

    expect(platformMetricsScrape())
        ->toContain('konventix_health_check_status{check="Database"} 0')
        ->toContain('konventix_health_check_status{check="Horizon"} 2')
        ->toContain('konventix_health_check_status{check="UsedDiskSpace"} 1');
});

it('renders the schedule heartbeat when one was written', function (): void {
    cache()->set('health:checks:schedule:latestHeartbeatAt', 1_700_000_000);

    expect(platformMetricsScrape())->toContain('konventix_schedule_last_heartbeat_timestamp_seconds 1700000000');
});

it('exposes the Horizon and queue collectors under the konventix namespace', function (): void {
    $scrape = platformMetricsScrape();

    expect($scrape)
        ->toContain('# TYPE konventix_horizon_status gauge')
        ->toContain('konventix_queue_size{connection="redis",queue="waiting-room"}');
});

it('declares every emitted platform metric in the catalogue', function (): void {
    $catalog = app(MetricCatalog::class);

    foreach (['queue_jobs_processed_total', 'queue_jobs_failed_total', 'log_records_total', 'health_check_status', 'schedule_last_heartbeat_timestamp_seconds', 'horizon_current_workload', 'queue_oldest_pending_job_age'] as $name) {
        expect($catalog->has($name))->toBeTrue($name);
    }
});

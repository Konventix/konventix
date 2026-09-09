<?php

declare(strict_types=1);

namespace App\Providers;

use App\Observability\CorrelationId;
use App\Observability\RequestContext;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;
use Konventix\Commerce\Health\WaitingRoomCheck;
use Konventix\Identity\Models\User;
use Konventix\Platform\Enums\Permission;
use Konventix\Platform\Observability\MetricCatalog;
use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;
use Konventix\Platform\Observability\Metrics;
use Laravel\Horizon\Horizon;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeServiceProvider;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\RedisNg;
use ReflectionProperty;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Enums\Status;
use Spatie\Health\Facades\Health;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Spatie\Prometheus\Collectors\Horizon\CurrentMasterSupervisorCollector;
use Spatie\Prometheus\Collectors\Horizon\CurrentProcessesPerQueueCollector;
use Spatie\Prometheus\Collectors\Horizon\CurrentWorkloadCollector;
use Spatie\Prometheus\Collectors\Horizon\FailedJobsPerHourCollector;
use Spatie\Prometheus\Collectors\Horizon\FailedRecentJobsCollector;
use Spatie\Prometheus\Collectors\Horizon\HorizonStatusCollector;
use Spatie\Prometheus\Collectors\Horizon\JobsPerMinuteCollector;
use Spatie\Prometheus\Collectors\Horizon\RecentJobsCollector;
use Spatie\Prometheus\Collectors\Queue\QueueDelayedJobsCollector;
use Spatie\Prometheus\Collectors\Queue\QueueOldestPendingJobCollector;
use Spatie\Prometheus\Collectors\Queue\QueuePendingJobsCollector;
use Spatie\Prometheus\Collectors\Queue\QueueReservedJobsCollector;
use Spatie\Prometheus\Collectors\Queue\QueueSizeCollector;
use Spatie\Prometheus\Facades\Prometheus;
use Throwable;

/**
 * Wiring for the operations dashboards — Pulse, Horizon and, in development,
 * Telescope.
 *
 * Host-level rather than module-level, and that is a boundary decision rather
 * than laziness. ADR-0003 says a *module* registers itself and is never wired
 * centrally; these are deployment tooling, not modules, and no module should
 * grow a dependency on whether this deployment happens to have Telescope
 * installed. The host composes them, the way it composes the demo seed.
 *
 * **Access is a capability, in every environment.** Each of these three ships
 * an authorization default of `app()->environment('local')`, which fails in
 * two directions at once: in production nobody can open them however the roles
 * are configured, and in development they are open to anyone who can reach the
 * host, with no login at all. Both are replaced below, so who may open a
 * dashboard is a role decision like everything else in the console (ADR-0013).
 *
 * Horizon and Telescope do not consult their gate from the middleware — they
 * call their own `auth()` callback, which is normally installed by an
 * application provider published by `horizon:install`. Those providers are not
 * published here, so the callbacks are set explicitly; defining the gates
 * alone would leave both dashboards on their local-only default.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    /** The queues config/horizon.php gives a supervisor (HorizonQueueCoverageTest). */
    public const array QUEUES = ['default', 'waiting-room', 'messaging'];

    public function register(): void
    {
        $this->bindMetricsRegistry();

        // Telescope is a `require-dev` package and excluded from package
        // discovery, so production never sees it: `composer install --no-dev`
        // leaves the class absent and this returns early. Registered by hand
        // rather than discovered so that stays true.
        if ($this->app->environment('production') || ! class_exists(TelescopeServiceProvider::class)) {
            return;
        }

        $this->app->register(TelescopeServiceProvider::class);

        $this->scrubTelescopeEntries();
    }

    /**
     * One registry for every role (ADR-0059). The package binds its own,
     * cache-backed adapter, which does a whole-blob read-modify-write and
     * loses increments between processes; promphp's RedisNg adapter does
     * atomic updates in Lua, and reusing the framework's phpredis client
     * means Valkey ACL credentials come from config/database.php like
     * everywhere else. Scoped, like the package's binding, so Octane hands
     * every request a fresh adapter over the connection it currently holds.
     *
     * `MetricCatalog` and `Metrics` themselves live in `Konventix\Platform\
     * Observability` (ADR-0003) so any module can record its own numbers
     * without importing the host; the host still binds the singletons here
     * and composes the `/metrics` endpoint.
     */
    private function bindMetricsRegistry(): void
    {
        $this->app->singleton(MetricCatalog::class);
        $this->app->singleton(Metrics::class, fn (): Metrics => new Metrics(
            $this->app->make(MetricCatalog::class),
            fn (): CollectorRegistry => $this->app->make(CollectorRegistry::class),
        ));

        $this->app->scoped(CollectorRegistry::class, function (): CollectorRegistry {
            return new CollectorRegistry($this->metricsStorage(), false);
        });
    }

    private function metricsStorage(): Adapter
    {
        if (config('prometheus.storage') !== 'redis') {
            return new InMemory;
        }

        RedisNg::setPrefix('konventix:metrics:');

        /** @var \Redis $client */
        $client = Redis::connection((string) config('prometheus.redis_connection', 'default'))->client();

        return RedisNg::fromExistingConnection($client);
    }

    /**
     * Telescope's published provider does this only outside `local`, on the
     * reasoning that a developer's own machine holds nothing worth hiding.
     * That reasoning does not survive contact with this application: local
     * databases here are seeded by `konventix:seed` with registrations,
     * invoices and subject requests, and a developer sharing a screen should
     * not be one click from somebody's session cookie.
     *
     * So the scrubbing is unconditional. Telescope never runs in production
     * at all (it is a `require-dev` package), which makes this the only
     * environment where it could leak anything.
     */
    private function scrubTelescopeEntries(): void
    {
        // The credential fields the settlement console posts are named here
        // one by one: Stripe's pair and optional publishable key for this
        // instance (ADR-0058 point 2), and Mollie's and Unzer's per-brand keys
        // (ADR-0039 point 4). Telescope is `require-dev`, so production never
        // sees these entries — but staging is exactly where an operator pastes
        // a live key, which is the case the console's own warning is about.
        Telescope::hideRequestParameters([
            '_token',
            'password',
            'password_confirmation',
            'current_password',
            'secretKey',
            'secret_key',
            'webhookSecret',
            'webhook_secret',
            'publishableKey',
            'publishable_key',
            'api_key',
            'private_key',
        ]);

        Telescope::hideRequestHeaders(['cookie', 'x-csrf-token', 'x-xsrf-token', 'authorization']);
    }

    public function boot(): void
    {
        $this->authorizePulse();
        $this->authorizeHorizon();
        $this->authorizeTelescope();
        $this->registerHealthChecks();
        $this->registerHostMetrics();
        $this->recordQueueAndLogMetrics();
        $this->scheduleHousekeeping();
        $this->correlateJobsAndCommands();
        $this->writeSummaryRecords();
    }

    /**
     * The gauges the host itself answers for. Domain gauges live in the
     * owning module's collector (Commerce: `CommerceGauges`).
     */
    private function registerHostMetrics(): void
    {
        $catalog = $this->app->make(MetricCatalog::class);

        $catalog->register(
            new MetricDefinition('http_requests_total', MetricKind::Counter, 'Requests handled, by method, response status and route name.', ['method', 'status', 'route']),
            new MetricDefinition('http_request_duration_seconds', MetricKind::Histogram, 'Request duration as seen by the application, for the allowlisted routes and `other`.', ['route'], (array) config('prometheus.http.buckets')),
            new MetricDefinition('build_info', MetricKind::Gauge, 'Always 1; the version label is the deployed image tag.', ['version']),
            new MetricDefinition('queue_jobs_processed_total', MetricKind::Counter, 'Jobs that finished without throwing, per queue.', ['queue']),
            new MetricDefinition('queue_jobs_failed_total', MetricKind::Counter, 'Jobs that exhausted their attempts, per queue and job class.', ['queue', 'job_class']),
            new MetricDefinition('log_records_total', MetricKind::Counter, 'Log records of warning and above, per level.', ['level']),
            new MetricDefinition('health_check_status', MetricKind::Gauge, 'Latest stored Spatie Health result per check: 0 ok, 1 warning, 2 failed, 3 crashed, 4 skipped.', ['check']),
            new MetricDefinition('schedule_last_heartbeat_timestamp_seconds', MetricKind::Gauge, 'When health:schedule-check-heartbeat last ran.'),
        );

        // The package's collectors, declared here so the catalogue is complete.
        foreach (['horizon_status', 'horizon_master_supervisors', 'horizon_jobs_per_minute', 'horizon_recent_jobs', 'horizon_failed_recent_jobs', 'horizon_failed_jobs_per_hour'] as $name) {
            $catalog->register(new MetricDefinition($name, MetricKind::Gauge, 'Horizon, via spatie/laravel-prometheus.'));
        }
        foreach (['horizon_current_workload', 'horizon_current_processes'] as $name) {
            $catalog->register(new MetricDefinition($name, MetricKind::Gauge, 'Horizon, via spatie/laravel-prometheus.', ['queue']));
        }
        foreach (['queue_size', 'queue_pending_jobs', 'queue_reserved_jobs', 'queue_delayed_jobs', 'queue_oldest_pending_job_age'] as $name) {
            $catalog->register(new MetricDefinition($name, MetricKind::Gauge, 'Queue, via spatie/laravel-prometheus.', ['connection', 'queue']));
        }

        Prometheus::addGauge('Build info')
            ->name('build_info')
            ->label('version')
            ->helpText('Always 1; the version label is the deployed image tag.')
            ->value(fn (): array => [[1, [(string) config('app.version')]]]);

        Prometheus::addGauge('Health check status')
            ->name('health_check_status')
            ->label('check')
            ->helpText('Latest stored Spatie Health result per check: 0 ok, 1 warning, 2 failed, 3 crashed, 4 skipped.')
            ->value(function (): array {
                $results = app(ResultStore::class)->latestResults();

                if ($results === null) {
                    return [];
                }

                return $results->storedCheckResults
                    ->map(fn (StoredCheckResult $result): array => [self::healthStatusValue($result->status), [$result->name]])
                    ->values()
                    ->all();
            });

        Prometheus::addGauge('Schedule heartbeat')
            ->name('schedule_last_heartbeat_timestamp_seconds')
            ->helpText('When health:schedule-check-heartbeat last ran.')
            ->value(function (): array {
                $check = ScheduleCheck::new();
                $at = cache()->store($check->getCacheStoreName())->get($check->getCacheKey());

                return is_numeric($at) ? [[(float) $at, []]] : [];
            });

        Prometheus::registerCollectorClasses([
            HorizonStatusCollector::class,
            CurrentMasterSupervisorCollector::class,
            CurrentWorkloadCollector::class,
            CurrentProcessesPerQueueCollector::class,
            JobsPerMinuteCollector::class,
            RecentJobsCollector::class,
            FailedRecentJobsCollector::class,
            FailedJobsPerHourCollector::class,
        ]);

        Prometheus::registerCollectorClasses([
            QueueSizeCollector::class,
            QueuePendingJobsCollector::class,
            QueueReservedJobsCollector::class,
            QueueDelayedJobsCollector::class,
            QueueOldestPendingJobCollector::class,
        ], ['redis', self::QUEUES]);
    }

    private static function healthStatusValue(string $status): int
    {
        return match ($status) {
            Status::ok()->value => 0,
            Status::warning()->value => 1,
            Status::failed()->value => 2,
            Status::crashed()->value => 3,
            default => 4,
        };
    }

    /**
     * `Job::getQueue()` is trustworthy on every real driver, but `SyncJob`
     * — the driver `phpunit.xml` and a plain `php artisan queue:work`-less
     * dev box both use — hardcodes it to the literal string `sync`
     * regardless of `onQueue()`. The queue the job actually asked for is
     * still on the protected `queue` property the constructor set, so read
     * that instead of trusting the accessor.
     */
    private static function jobQueueName(object $job): string
    {
        if (property_exists($job, 'queue')) {
            $queue = (new ReflectionProperty($job, 'queue'))->getValue($job);

            if (is_string($queue) && $queue !== '') {
                return $queue;
            }
        }

        return 'default';
    }

    /**
     * Event-time counters the host owns. The log listener guards against
     * itself: a Redis failure while counting a record would log an error,
     * which would count a record.
     */
    private function recordQueueAndLogMetrics(): void
    {
        Event::listen(JobProcessed::class, function (JobProcessed $event): void {
            $this->quietly(fn () => app(Metrics::class)->increment('queue_jobs_processed_total', [
                'queue' => self::jobQueueName($event->job),
            ]));
        });

        Event::listen(JobFailed::class, function (JobFailed $event): void {
            $this->quietly(fn () => app(Metrics::class)->increment('queue_jobs_failed_total', [
                'queue' => self::jobQueueName($event->job),
                'job_class' => class_basename($event->job->resolveName()),
            ]));
        });

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            static $counting = false;

            if ($counting || ! in_array($event->level, ['warning', 'error', 'critical', 'alert', 'emergency'], true)) {
                return;
            }

            $counting = true;

            try {
                app(Metrics::class)->increment('log_records_total', ['level' => $event->level]);
            } catch (Throwable) {
                // Deliberately silent: reporting would log, and logging would count.
            } finally {
                $counting = false;
            }
        });
    }

    private function quietly(callable $record): void
    {
        try {
            $record();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The half of ADR-0056 that is not a request, plus the fields ADR-0059
     * adds. Order on JobProcessing: the framework restores the dispatching
     * request's Context first (the context provider boots before this one),
     * so `addIf` keeps an inherited correlation id and `forJob` overwrites
     * the inherited route with the job class.
     */
    private function correlateJobsAndCommands(): void
    {
        Event::listen(RouteMatched::class, function (RouteMatched $event): void {
            RequestContext::forRoute($event->route->getName());
        });

        Event::listen(Authenticated::class, function (Authenticated $event): void {
            RequestContext::forUser((string) $event->user->getAuthIdentifier());
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            CorrelationId::ensure();
            RequestContext::forJob($event->job->resolveName(), self::jobQueueName($event->job));
            Context::addHidden('job_started_at', microtime(true));
        });

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            CorrelationId::ensure();
            RequestContext::forCommand((string) $event->command);
        });
    }

    /**
     * One record per unit of work (ADR-0059): what the ingress access line
     * knows about a request, but with the route name, the brand and the
     * user, and the same for every job and every scheduled task. Requests
     * at info, finished tasks at debug (production logs at info and never
     * sees them), failures at error.
     */
    private function writeSummaryRecords(): void
    {
        Event::listen(RequestHandled::class, function (RequestHandled $event): void {
            if (in_array($event->request->path(), RequestContext::SKIPPED_PATHS, true)) {
                return;
            }

            $startedAt = (float) $event->request->server('REQUEST_TIME_FLOAT', microtime(true));

            Log::info('http.request', [
                'method' => $event->request->method(),
                'route' => $event->request->route()?->getName(),
                'status' => $event->response->getStatusCode(),
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 1),
            ]);
        });

        Event::listen(JobProcessed::class, function (JobProcessed $event): void {
            Log::info('queue.job', $this->jobSummary($event->job, 'processed'));
        });

        Event::listen(JobFailed::class, function (JobFailed $event): void {
            Log::error('queue.job', $this->jobSummary($event->job, 'failed') + ['exception' => $event->exception]);
        });

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            Log::debug('schedule.task', [
                'command' => $event->task->getSummaryForDisplay(),
                'exit_code' => $event->task->exitCode,
                'duration_ms' => round($event->runtime * 1000, 1),
            ]);
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            Log::error('schedule.task', [
                'command' => $event->task->getSummaryForDisplay(),
                'exit_code' => $event->task->exitCode,
                'exception' => $event->exception,
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function jobSummary(Job $job, string $outcome): array
    {
        $startedAt = Context::getHidden('job_started_at');

        return [
            'job' => $job->resolveName(),
            'queue' => self::jobQueueName($job),
            'outcome' => $outcome,
            'attempts' => $job->attempts(),
            'duration_ms' => is_float($startedAt) ? round((microtime(true) - $startedAt) * 1000, 1) : null,
        ];
    }

    /**
     * What `/up` cannot tell you.
     *
     * Laravel's built-in health route proves PHP answered, which is exactly
     * the failure mode that never happens: the interesting outage is Postgres
     * refusing connections, Redis full, the scheduler dead, or the disk
     * finished — every one of which leaves the web role answering `/up`
     * cheerfully.
     *
     * `ScheduleCheck` needs `health:schedule-check-heartbeat` running on the
     * schedule to have anything to report, which is wired below; without it it
     * reports the scheduler as down, correctly, since nothing is ticking.
     */
    private function registerHealthChecks(): void
    {
        $checks = [
            DatabaseCheck::new(),
            RedisCheck::new(),
            CacheCheck::new(),
            ScheduleCheck::new()->heartbeatMaxAgeInMinutes(5),
            HorizonCheck::new(),
            // ADR-0047 point 5, Task 13: the waiting room's admission tick
            // is a Horizon queue worker like any other, but "Horizon is
            // running" (the check above) does not prove THIS supervisor is
            // draining its queue — WaitingRoomCheck reads the tick's own
            // heartbeat.
            WaitingRoomCheck::new(),
            // Debug mode in production leaks stack traces, environment and
            // query contents to whoever triggers an error.
            DebugModeCheck::new(),
        ];

        if (config()->boolean('health.disk.enabled')) {
            $disk = UsedDiskSpaceCheck::new()
                ->warnWhenUsedSpaceIsAbovePercentage(80)
                ->failWhenUsedSpaceIsAbovePercentage(90);

            if (is_string($path = config('health.disk.path'))) {
                $disk->filesystemName($path);
            }

            $checks[] = $disk;
        }

        Health::checks($checks);
    }

    /**
     * Two of these are load-bearing rather than tidy.
     *
     * `horizon:snapshot` is what fills the metrics dashboard at all — running
     * the Horizon process alone leaves it permanently blank, which reads as
     * "Horizon is broken" rather than "nobody scheduled the snapshot".
     *
     * `telescope:prune` keeps one of two stores of personal data from growing
     * without bound; Pulse holds the other (user ids) and trims itself, via
     * `ingest.trim.lottery` and `storage.trim.keep` in `config/pulse.php`,
     * with nothing to schedule here. Neither store is covered by Compliance's
     * retention rules (D-CPL-01 names the delivery log and the audit trail).
     * Until it is, these windows are the only thing bounding them —
     * deliberately shorter than anything Compliance governs, because nothing
     * here is evidence of anything.
     */
    private function scheduleHousekeeping(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('horizon:snapshot')->everyFiveMinutes();

            // Pulse trims itself: `ingest.trim.lottery` runs a chance of it
            // on every ingest, and `storage.trim.keep` bounds what survives.
            // There is no `pulse:trim` artisan command to schedule here.

            if (class_exists(Telescope::class)) {
                $schedule->command('telescope:prune --hours=48')->daily();
            }

            // Health results are stored, not computed on request: an external
            // monitor polling every thirty seconds must not run a disk scan
            // and a Redis round-trip each time.
            $schedule->command('health:check')->everyFiveMinutes();
            $schedule->command('health:schedule-check-heartbeat')->everyMinute();

            // Off-site copies. GoBD keeps archived invoices for ten years
            // (ADR-0031, §147 AO), which is far longer than any single disk
            // should be trusted with them. Skippable per ADR-0049 §8 where
            // the platform below owns backups.
            if (config()->boolean('backup.schedule_enabled')) {
                $schedule->command('backup:clean')->dailyAt('01:00');
                $schedule->command('backup:run')->dailyAt('01:30');
            }
        });
    }

    /**
     * Pulse does read its gate, but registers its own local-only default
     * through `callAfterResolving`. Defining ours through the facade resolves
     * the Gate first, which drains that pending callback, so this definition
     * lands last and wins.
     */
    private function authorizePulse(): void
    {
        Gate::define('viewPulse', fn (?User $user): bool => $user?->can(Permission::ViewDiagnostics->value) ?? false);
    }

    private function authorizeHorizon(): void
    {
        Gate::define('viewHorizon', fn (?User $user): bool => $user?->can(Permission::ViewDiagnostics->value) ?? false);

        Horizon::auth(fn (Request $request): bool => Gate::check('viewHorizon', [$request->user()]));
    }

    /**
     * Held apart from the other two on purpose. Telescope records request
     * bodies, so opening it reads other people's personal data — registrations,
     * subject requests, invoice recipients — rather than metrics about how busy
     * the system is. A role may reasonably hold one capability and not this one.
     */
    private function authorizeTelescope(): void
    {
        if (! class_exists(Telescope::class)) {
            return;
        }

        Gate::define('viewTelescope', fn (?User $user): bool => $user?->can(Permission::ViewRequestLog->value) ?? false);

        Telescope::auth(fn (Request $request): bool => Gate::check('viewTelescope', [$request->user()]));
    }
}

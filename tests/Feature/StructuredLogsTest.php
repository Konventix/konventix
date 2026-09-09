<?php

declare(strict_types=1);

use App\Observability\RequestContext;
use Illuminate\Console\Command;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Konventix\Identity\Models\User;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * What every log record carries besides the correlation id, and the one
 * summary record per request, job and scheduled task (ADR-0059 section on
 * logs; #157 wants a purchase followed from ingress to mail). The handler is
 * pushed onto the real Monolog logger, as CorrelationIdTest does, so the
 * framework's Context processor runs.
 */
final class StructuredLogsProbeJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Log::info('structured probe from job');
    }
}

final class StructuredLogsProbeCommand extends Command
{
    protected $signature = 'structured-logs:probe';

    public function handle(): int
    {
        Log::info('structured probe from command');

        return self::SUCCESS;
    }
}

function structuredLogHandler(): TestHandler
{
    $monolog = Log::driver()->getLogger();
    expect($monolog)->toBeInstanceOf(Logger::class);
    $handler = new TestHandler;
    $monolog->pushHandler($handler);

    return $handler;
}

/** @return list<LogRecord> */
function structuredRecords(TestHandler $handler, string $message): array
{
    return array_values(array_filter($handler->getRecords(), fn (LogRecord $r): bool => $r->message === $message));
}

beforeEach(function (): void {
    config()->set('app.version', '9.9.9-test');
    Route::middleware('web')->get('/structured-probe', function (): array {
        Log::info('structured probe from request');

        return ['ok' => true];
    })->name('structured.probe');
});

it('puts release, route and brand on a request record, and user once signed in', function (): void {
    $brand = $this->withBrand();
    $handler = structuredLogHandler();

    $this->get('/structured-probe')->assertOk();
    $anonymous = structuredRecords($handler, 'structured probe from request')[0];

    expect($anonymous->extra[RequestContext::RELEASE] ?? null)->toBe('9.9.9-test')
        ->and($anonymous->extra[RequestContext::ROUTE] ?? null)->toBe('structured.probe')
        ->and($anonymous->extra[RequestContext::BRAND] ?? null)->toBe($brand->slug)
        ->and($anonymous->extra)->not->toHaveKey(RequestContext::USER);

    $user = User::factory()->create();
    $this->actingAs($user)->get('/structured-probe')->assertOk();
    $signedIn = structuredRecords($handler, 'structured probe from request')[1];

    expect($signedIn->extra[RequestContext::USER] ?? null)->toBe($user->getKey());
});

it('writes one http.request summary record with method, route, status and duration', function (): void {
    $this->withBrand();
    $handler = structuredLogHandler();

    $this->get('/structured-probe')->assertOk();

    $summary = structuredRecords($handler, 'http.request');

    expect($summary)->toHaveCount(1)
        ->and($summary[0]->level->getName())->toBe('INFO')
        ->and($summary[0]->context['method'])->toBe('GET')
        ->and($summary[0]->context['route'])->toBe('structured.probe')
        ->and($summary[0]->context['status'])->toBe(200)
        ->and($summary[0]->context['duration_ms'])->toBeFloat()
        // Below the request's own boot, not a stale REQUEST_TIME_FLOAT the
        // fallback would leave behind (ADR-0059's ruling on measurement).
        ->and($summary[0]->context['duration_ms'])->toBeLessThan(5000.0);
});

it('writes no summary record for the diagnostics endpoints', function (): void {
    config()->set('health.endpoint_secret', 'metrics-test-secret');
    $handler = structuredLogHandler();

    $this->get('/up')->assertOk();
    $this->withHeader('Authorization', 'Bearer metrics-test-secret')->get('/metrics')->assertOk();

    expect(structuredRecords($handler, 'http.request'))->toBe([]);
});

it('gives a job its class and queue, keeps the inherited brand, and drops the route', function (): void {
    $brand = $this->withBrand();
    $handler = structuredLogHandler();
    Route::middleware('web')->get('/structured-dispatch', function (): array {
        StructuredLogsProbeJob::dispatch()->onQueue('messaging');

        return ['ok' => true];
    })->name('structured.dispatch');

    $this->get('/structured-dispatch')->assertOk();

    $fromJob = structuredRecords($handler, 'structured probe from job')[0];
    $summary = structuredRecords($handler, 'queue.job')[0];

    expect($fromJob->extra[RequestContext::JOB] ?? null)->toBe(StructuredLogsProbeJob::class)
        ->and($fromJob->extra[RequestContext::QUEUE] ?? null)->toBe('messaging')
        ->and($fromJob->extra[RequestContext::BRAND] ?? null)->toBe($brand->slug)
        ->and($fromJob->extra)->not->toHaveKey(RequestContext::ROUTE)
        ->and($summary->context['outcome'])->toBe('processed')
        ->and($summary->context['job'])->toBe(StructuredLogsProbeJob::class)
        ->and($summary->context['duration_ms'])->toBeFloat();
});

it('gives a command its name and the release', function (): void {
    // Artisan::call() fires no CommandStarting under the test runner unless
    // Symfony's console events are bridged by hand first (tests.md, pinned
    // by CorrelationIdTest): Kernel::__construct only reroutes them when
    // ! runningUnitTests(), so the CommandStarting listener that assigns
    // RequestContext::forCommand() would otherwise never run here.
    $kernel = app(ConsoleKernel::class);
    $kernel->rerouteSymfonyCommandEvents();
    $kernel->setArtisan(null);
    $kernel->registerCommand(new StructuredLogsProbeCommand);
    $handler = structuredLogHandler();

    Artisan::call('structured-logs:probe');

    $record = structuredRecords($handler, 'structured probe from command')[0];

    expect($record->extra[RequestContext::COMMAND] ?? null)->toBe('structured-logs:probe')
        ->and($record->extra[RequestContext::RELEASE] ?? null)->toBe('9.9.9-test');
});

it('configures the stderr channel to include stack traces', function (): void {
    expect(config('logging.channels.stderr.formatter_with.includeStacktraces'))->toBeTrue();

    config()->set('logging.channels.stderr.formatter', JsonFormatter::class);
    $formatter = Log::channel('stderr')->getLogger()->getHandlers()[0]->getFormatter();
    $json = $formatter->format(new LogRecord(now()->toDateTimeImmutable(), 'test', Level::Error, 'boom', ['exception' => new RuntimeException('boom')]));

    expect($json)->toContain('"trace"');
});

it('reports a failed scheduled task with its command, exit code and duration', function (): void {
    $handler = structuredLogHandler();
    $task = app(Schedule::class)->command('inspire');

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFailed($task, new RuntimeException('scheduled probe boom')));

    $record = structuredRecords($handler, 'schedule.task')[0];

    expect($record->level->getName())->toBe('ERROR')
        ->and($record->context['command'])->toBeString()
        ->and($record->context)->toHaveKey('exit_code')
        ->and($record->context['duration_ms'])->toBeFloat();
});

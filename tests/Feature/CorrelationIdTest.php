<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;

final class CorrelationIdProbeJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Log::info('correlation job probe');
    }
}

final class CorrelationIdProbeCommand extends Command
{
    protected $signature = 'correlation:probe';

    public function handle(): int
    {
        Log::info('correlation command probe');

        return self::SUCCESS;
    }
}

/**
 * One ID per unit of work, in every log line it writes (ADR-0056, GitLab
 * #89). A request adopts the reverse proxy's `X-Request-ID` or mints one,
 * answers with it, and the queue carries it into the jobs the request
 * dispatched; a job or command that inherited nothing mints its own.
 */
beforeEach(function (): void {
    Route::get('/correlation-probe', fn (): array => ['ok' => true]);
});

it('answers a request that brought no id with a freshly minted ulid', function (): void {
    $response = $this->get('/correlation-probe')->assertOk();

    expect($response->headers->get('X-Request-ID'))->toBeString()->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

/**
 * A Monolog test handler on the default channel, behind the processor the
 * framework pushes there — what it records is what a log pipeline would see,
 * `extra` included. `Log::spy()` would replace the whole logger and never
 * reach that processor.
 */
function correlationLogHandler(): TestHandler
{
    $monolog = Log::driver()->getLogger();

    expect($monolog)->toBeInstanceOf(Logger::class);

    $handler = new TestHandler;
    $monolog->pushHandler($handler);

    return $handler;
}

/**
 * @return list<LogRecord>
 */
function correlationRecordsNamed(TestHandler $handler, string $message): array
{
    return array_values(array_filter(
        $handler->getRecords(),
        fn (LogRecord $record): bool => $record->message === $message,
    ));
}

it('writes the id into every log record of the request', function (): void {
    Route::get('/correlation-logging-probe', function (): array {
        Log::info('correlation probe');

        return ['ok' => true];
    });
    $handler = correlationLogHandler();

    $response = $this->get('/correlation-logging-probe')->assertOk();

    $records = correlationRecordsNamed($handler, 'correlation probe');

    expect($records)->toHaveCount(1)
        ->and($records[0]->extra['correlation_id'] ?? null)->toBe($response->headers->get('X-Request-ID'));
});

it('adopts the id the reverse proxy sent', function (): void {
    $response = $this->get('/correlation-probe', ['X-Request-ID' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90'])->assertOk();

    expect($response->headers->get('X-Request-ID'))->toBe('a1b2c3d4e5f60718293a4b5c6d7e8f90');
});

/**
 * ingress-nginx forwards whatever `X-Request-ID` the client sent, so this
 * value is untrusted input on its way into every log line. Anything that is
 * not a plain token is replaced, never echoed.
 */
it('replaces an id it could not print safely', function (string $sent): void {
    $response = $this->get('/correlation-probe', ['X-Request-ID' => $sent])->assertOk();

    expect($response->headers->get('X-Request-ID'))->toBeString()
        ->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->not->toBe($sent);
})->with([
    'spaces' => 'looks fine but has spaces',
    'too short' => 'abc',
    'too long' => str_repeat('a', 65),
    'line break' => "abc12345\r\nX-Injected: yes",
]);

it('answers a 404 with the id too', function (): void {
    // No brand owns this host, so ResolveBrand aborts inside the `web`
    // pipeline — the header must survive the exception handler's response.
    $response = $this->get('http://nobody.test/')->assertNotFound();

    expect($response->headers->get('X-Request-ID'))->toBeString()->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

it('shares the id with the page, so a report from the browser can name it', function (): void {
    $this->withBrand();

    $response = $this->get('/')->assertOk();

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('correlationId', $response->headers->get('X-Request-ID')));
});

it('carries the id of the dispatching request into the job that runs later, elsewhere', function (): void {
    Route::get('/correlation-dispatch-probe', function (): array {
        CorrelationIdProbeJob::dispatch()->onConnection('database');

        return ['ok' => true];
    });
    $handler = correlationLogHandler();

    $id = $this->get('/correlation-dispatch-probe')->assertOk()->headers->get('X-Request-ID');

    expect(DB::table('jobs')->value('payload'))->toContain($id);

    // A worker is another process with a context of its own; a flushed one
    // stands in for it here.
    Context::flush();
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

    $records = correlationRecordsNamed($handler, 'correlation job probe');

    expect($records)->toHaveCount(1)
        ->and($records[0]->extra['correlation_id'] ?? null)->toBe($id);
});

it('mints an id of its own for each job that inherited none', function (): void {
    $handler = correlationLogHandler();
    Context::flush();

    CorrelationIdProbeJob::dispatch()->onConnection('database');
    CorrelationIdProbeJob::dispatch()->onConnection('database');

    expect(DB::table('jobs')->pluck('payload')->implode(''))->not->toContain('correlation_id');

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

    $ids = array_map(
        fn (LogRecord $record): mixed => $record->extra['correlation_id'] ?? null,
        correlationRecordsNamed($handler, 'correlation job probe'),
    );

    expect($ids)->toHaveCount(2)
        ->each->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
    expect($ids[0])->not->toBe($ids[1]);
});

it('mints an id for a console command', function (): void {
    $handler = correlationLogHandler();
    Context::flush();

    // Under the test runner the kernel leaves Symfony's console events
    // unbridged (`Kernel::__construct` skips `rerouteSymfonyCommandEvents()`
    // when running unit tests), so `CommandStarting` would never fire here
    // however the listener is wired. Bridge them the way every real process
    // has them, on a fresh Artisan instance — the bridge attaches at
    // construction, and RefreshDatabase may already have built one.
    $kernel = app(ConsoleKernel::class);
    $kernel->rerouteSymfonyCommandEvents();
    $kernel->setArtisan(null);
    $kernel->registerCommand(new CorrelationIdProbeCommand);

    Artisan::call('correlation:probe');

    $records = correlationRecordsNamed($handler, 'correlation command probe');

    expect($records)->toHaveCount(1)
        ->and($records[0]->extra['correlation_id'] ?? null)->toBeString()->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

it('prints the id on the error page the framework renders', function (): void {
    config(['app.debug' => false]);
    Route::get('/correlation-error-probe', fn () => abort(500));

    $response = $this->get('/correlation-error-probe')->assertStatus(500);

    $response->assertSee($response->headers->get('X-Request-ID'));
});

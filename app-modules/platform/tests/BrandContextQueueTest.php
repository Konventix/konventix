<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;

/**
 * ADR-0010: queued jobs carry the brand context and workers restore it before
 * handling. The payload is the thing under test, so these run against the
 * `database` driver rather than `sync` or `Queue::fake()` — both of which would
 * let the job see a context that dispatch left lying around in the same process.
 */
beforeEach(function (): void {
    config()->set('queue.default', 'database');

    BrandProbeJob::$observed = [];
});

/** Stand-in for any queued job dispatched out of a brand request. */
class BrandProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var array<string, string|null> */
    public static array $observed = [];

    public function __construct(private readonly string $label) {}

    public function handle(BrandContext $context): void
    {
        self::$observed[$this->label] = $context->id();
    }
}

/**
 * @return array<string, mixed>
 */
function queuedPayload(): array
{
    return json_decode((string) DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
}

function workOneJob(): void
{
    Artisan::call('queue:work', [
        'connection' => 'database',
        '--once' => true,
        '--sleep' => 0,
        '--tries' => 1,
    ]);
}

it('writes the brand into the payload of a job dispatched under a brand', function (): void {
    $brand = $this->withBrand();

    BrandProbeJob::dispatch('dispatched under a brand');

    expect(queuedPayload())->toHaveKey('brandId')
        ->and(queuedPayload()['brandId'])->toBe($brand->getKey());
});

it('restores the brand before the job handles, even in a worker that never saw the request', function (): void {
    $brand = $this->withBrand();

    BrandProbeJob::dispatch('restored');

    // The worker is a separate long-lived process; nothing of the dispatching
    // request survives into it but the payload.
    app(BrandContext::class)->forget();

    workOneJob();

    expect(BrandProbeJob::$observed)->toBe(['restored' => $brand->getKey()]);
});

it('clears the brand once the job is done, so it cannot leak into the next one', function (): void {
    $brand = $this->withBrand();

    BrandProbeJob::dispatch('first');
    app(BrandContext::class)->forget();

    BrandProbeJob::dispatch('second, dispatched with no brand');

    workOneJob();

    expect(BrandProbeJob::$observed['first'])->toBe($brand->getKey())
        ->and(app(BrandContext::class)->hasBrand())->toBeFalse();

    workOneJob();

    expect(BrandProbeJob::$observed['second, dispatched with no brand'])->toBeNull();
});

it('dispatches without a brand rather than failing when none is in context', function (): void {
    app(BrandContext::class)->forget();

    BrandProbeJob::dispatch('unbranded');

    expect(queuedPayload())->not->toHaveKey('brandId');

    workOneJob();

    expect(BrandProbeJob::$observed)->toBe(['unbranded' => null])
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('hands each job the brand it was dispatched under, not the one before it', function (): void {
    $context = app(BrandContext::class);
    $first = Brand::factory()->create();
    $second = Brand::factory()->create();

    $context->set($first);
    BrandProbeJob::dispatch('first brand');

    $context->set($second);
    BrandProbeJob::dispatch('second brand');

    $context->forget();

    workOneJob();
    workOneJob();

    expect(BrandProbeJob::$observed)->toBe([
        'first brand' => $first->getKey(),
        'second brand' => $second->getKey(),
    ]);
});

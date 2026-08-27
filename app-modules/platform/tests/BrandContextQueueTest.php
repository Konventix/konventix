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
 * Stand-in for a `sync`-queued job that itself dispatches another job
 * before returning (`IssueCreditNote` dispatching `GenerateCreditNoteDocument`
 * `afterCommit()`, mid-transaction, is the real shape) — proves the
 * propagator restores a *stack* of interrupted brands, one per nesting
 * level, not a single slot.
 */
class BrandSwitchingProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public static ?string $brandAfterInnerDispatch = null;

    public function __construct(private readonly string $switchToBrandId) {}

    public function handle(BrandContext $context): void
    {
        $context->set(Brand::query()->findOrFail($this->switchToBrandId));

        BrandProbeJob::dispatch('inner, dispatched under the switched brand');

        self::$brandAfterInnerDispatch = $context->id();
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

/**
 * Task 3 (mail-and-receipts) surfaced this on the `sync` queue specifically:
 * `IssueCreditNote` dispatches `GenerateCreditNoteDocument` `afterCommit()`
 * from inside a brand-scoped action. On `sync` — every test, and any
 * `QUEUE_CONNECTION=sync` deployment — `dispatch()` runs the job inline, in
 * the same process and the same call stack as the code that dispatched it.
 * The old `JobProcessed` handler cleared the brand outright, which wiped the
 * *dispatching* code's own ambient brand the moment the job it dispatched
 * finished — the next brand-owned row that code tried to create had none to
 * stamp. These run on `sync`, not `database`: the leaking-ambient bug only
 * exists when dispatch and handling share a call stack.
 */
it('restores the ambient brand once a job dispatched inline on the sync queue finishes', function (): void {
    config()->set('queue.default', 'sync');

    $a = $this->withBrand();

    BrandProbeJob::dispatch('inline under sync');

    expect(BrandProbeJob::$observed)->toBe(['inline under sync' => $a->getKey()])
        ->and(app(BrandContext::class)->id())->toBe($a->getKey());
});

it("restores each nested job's own interrupted brand, not the outermost one — a stack", function (): void {
    config()->set('queue.default', 'sync');

    $a = $this->withBrand();
    $b = Brand::factory()->create();

    BrandProbeJob::$observed = [];
    BrandSwitchingProbeJob::$brandAfterInnerDispatch = null;

    BrandSwitchingProbeJob::dispatch($b->getKey());

    expect(BrandProbeJob::$observed)->toBe(['inner, dispatched under the switched brand' => $b->getKey()])
        // Restored to $b — the brand interrupted by the *inner* dispatch —
        // not to $a, proving each nesting level restores its own frame.
        ->and(BrandSwitchingProbeJob::$brandAfterInnerDispatch)->toBe($b->getKey())
        // And once the outer job itself finishes, its own interrupted
        // brand ($a) comes back.
        ->and(app(BrandContext::class)->id())->toBe($a->getKey());
});

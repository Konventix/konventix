<?php

declare(strict_types=1);

namespace Konventix\Platform\Brands;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Realtime\BrandBroadcasting;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TickReceived;
use Spatie\Permission\PermissionRegistrar;

/**
 * Carries the brand across the queue boundary (ADR-0001, ADR-0010).
 *
 * Without this, a job dispatched from a brand request would run unscoped in the
 * worker: the BelongsToBrand scope would not constrain, and `creating` would
 * fail for want of a brand. The brand id rides along in the job payload and is
 * restored before the job is handled, then *restored to whatever it was
 * before* once the job finishes — not simply cleared.
 *
 * That distinction matters on a `sync` queue (every test, and any
 * `QUEUE_CONNECTION=sync` deployment): there, `dispatch()` runs the job
 * inline, in the same process and often the same call stack as the code that
 * dispatched it (e.g. `IssueCreditNote` dispatching `GenerateCreditNoteDocument`
 * `afterCommit()` from inside a request that is itself mid-brand). Clearing
 * the context on `JobProcessed` used to wipe that ambient brand out from
 * under the rest of the request/command — `BelongsToBrand::creating()` would
 * then fail for the next brand-owned row created, and ADR-0029's admin-host
 * hardening would zero out the next brand-scoped read. Saving and restoring
 * the interrupted brand (a stack, since a `sync`-queued job can itself
 * dispatch another) keeps the surrounding code's brand intact across the
 * interruption instead.
 */
class PropagatesBrandContextToQueue
{
    /**
     * The brand each nested `JobProcessing` interrupted, in dispatch order —
     * a stack because a `sync`-queued job can itself dispatch another job
     * before returning. `null` entries are real: they mean the interrupted
     * code had no brand of its own, and restoring must set "no brand" back,
     * not silently leave whatever the job set.
     *
     * This instance is resolved once, at boot, and its `register()` closures
     * capture `$this` for the worker's entire lifetime (Octane) or the whole
     * `queue:work` process — so the stack is not request-scoped on its own.
     * Every push (`JobProcessing`) is paired with exactly one pop
     * (`JobProcessed` or `JobExceptionOccurred` — see `register()`'s
     * docblock on why not also `JobFailed`), so a correctly-running worker
     * never accumulates entries across jobs. The Octane listeners below are
     * defence in depth regardless: the pairing invariant depends on every
     * job attempt reaching one of those two events, which is true for every
     * path this codebase's queue drivers take today but is not something a
     * future job middleware, a fatal error PHP itself intercepts before the
     * catch block, or an exotic driver is guaranteed to preserve — and a
     * worker that serves thousands of unrelated HTTP requests over its
     * lifetime should not carry a queue bug into all of them. Resetting on
     * every request/task/tick this worker picks up next bounds the damage
     * of a hypothetical unbalanced push to the single job (or `sync` request)
     * that caused it.
     *
     * @var list<Brand|null>
     */
    private array $interruptedBrands = [];

    public function __construct(private readonly Application $app) {}

    public function register(): void
    {
        Queue::createPayloadUsing(function (): array {
            $brandId = $this->app->make(BrandContext::class)->id();

            return $brandId === null ? [] : ['brandId' => $brandId];
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $context = $this->app->make(BrandContext::class);

            $this->interruptedBrands[] = $context->current();
            $context->forget();

            $brandId = $event->job->payload()['brandId'] ?? null;

            if (! is_string($brandId)) {
                return;
            }

            $brand = Brand::query()->find($brandId);

            if ($brand instanceof Brand) {
                $context->set($brand);

                // Broadcasts fired by this job sign with the dispatching
                // brand's Reverb app (ADR-0025), and capability checks inside
                // the job resolve against the dispatching brand's scope
                // (ADR-0029).
                $this->app->make(BrandBroadcasting::class)->useBrand($brand);
                $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($brand->getKey());
            }
        });

        $restoreInterruptedBrand = function (): void {
            if ($this->interruptedBrands === []) {
                // Defensive only: every restore listener below is paired
                // with the JobProcessing push above. An unbalanced pop must
                // never silently corrupt an unrelated brand by popping the
                // wrong frame, so do nothing rather than guess.
                return;
            }

            $interrupted = array_pop($this->interruptedBrands);
            $context = $this->app->make(BrandContext::class);

            if ($interrupted instanceof Brand) {
                $context->set($interrupted);
            } else {
                $context->forget();
            }
        };

        Event::listen(JobProcessed::class, $restoreInterruptedBrand);

        // Deliberately not JobFailed too: on every driver — confirmed here
        // for `sync` in Illuminate\Queue\SyncQueue::handleException(), and
        // true generally in Worker::handleJobException() — a failing job's
        // *final* attempt fires JobExceptionOccurred and then JobFailed as a
        // second event for that same JobProcessing push. Listening to both
        // would pop this stack twice for one push and desynchronize it;
        // JobExceptionOccurred alone already covers every exception on every
        // attempt, final or not.
        Event::listen(JobExceptionOccurred::class, $restoreInterruptedBrand);

        $this->registerOctaneStackReset();
    }

    /**
     * Defence in depth (see `$interruptedBrands`'s docblock): clears the
     * stack at the start of whatever this Octane worker serves next, so a
     * hypothetical unbalanced push from one request/task/tick can never
     * follow into another. `class_exists()`-guarded because Octane's event
     * classes only autoload when the package is actually installed — this
     * runs in every environment, Octane or not (`.ai/rules/providers.md`'s
     * "a boot-time singleton never constructor-injects a scoped binding"
     * lesson is the same family of Octane-worker-lifetime bug this guards
     * against, one step further down the stack).
     */
    private function registerOctaneStackReset(): void
    {
        $resetInterruptedBrands = function (): void {
            $this->interruptedBrands = [];
        };

        if (class_exists(RequestReceived::class)) {
            Event::listen(RequestReceived::class, $resetInterruptedBrands);
        }

        if (class_exists(TaskReceived::class)) {
            Event::listen(TaskReceived::class, $resetInterruptedBrands);
        }

        if (class_exists(TickReceived::class)) {
            Event::listen(TickReceived::class, $resetInterruptedBrands);
        }
    }
}

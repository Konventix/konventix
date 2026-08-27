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
    }
}

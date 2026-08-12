<?php

declare(strict_types=1);

namespace Konventix\Platform\Brands;

use Illuminate\Contracts\Foundation\Application;
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
 * restored before the job is handled, then cleared so the long-lived worker
 * process never leaks one job's brand into the next.
 */
class PropagatesBrandContextToQueue
{
    public function __construct(private readonly Application $app) {}

    public function register(): void
    {
        Queue::createPayloadUsing(function (): array {
            $brandId = $this->app->make(BrandContext::class)->id();

            return $brandId === null ? [] : ['brandId' => $brandId];
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $context = $this->app->make(BrandContext::class);
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

        Event::listen(JobProcessed::class, function (): void {
            $this->app->make(BrandContext::class)->forget();
        });
    }
}

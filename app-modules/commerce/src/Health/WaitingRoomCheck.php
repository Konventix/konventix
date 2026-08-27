<?php

declare(strict_types=1);

namespace Konventix\Commerce\Health;

use Konventix\Commerce\WaitingRoom\WaitingRoomStore;
use Konventix\Platform\Models\Brand;
use Laravel\Pennant\Feature;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * ADR-0047 point 5, Task 13: proves the admission tick is actually
 * ticking, not merely scheduled. `AdmitWaitingRoomBatch::handle()` writes
 * `waiting-room:{brand}:last_admission` on every run it reaches, whether
 * or not anyone was admitted — so a feature-on brand with people queued
 * and no recent admission run means `commerce:waiting-room-tick`, its
 * schedule, or Horizon's `waiting-room` supervisor has stopped, not that
 * the queue happens to be quiet.
 *
 * Registered the same way `HorizonCheck` is
 * (`App\Providers\ObservabilityServiceProvider::registerHealthChecks()`):
 * a `Spatie\Health\Checks\Check`, the package's own contract, rather than
 * a Commerce-side hook — the host layer already composes every other
 * operations dashboard check the same way, and Commerce is Core, always
 * loaded, so there is no boundary this crosses that the others don't.
 */
final class WaitingRoomCheck extends Check
{
    private const STALE_AFTER_MINUTES = 2;

    public function __construct(private readonly WaitingRoomStore $store)
    {
        parent::__construct();
    }

    public function run(): Result
    {
        $stalled = [];

        foreach (Brand::query()->get() as $brand) {
            if (! Feature::for($brand)->active('commerce:waiting-room')) {
                continue;
            }

            $brandId = $brand->getKey();

            if ($this->store->depth($brandId) <= 0) {
                continue;
            }

            $lastAdmission = $this->store->lastAdmissionAt($brandId);

            if ($lastAdmission === null || $lastAdmission->lt(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
                $stalled[] = $brand->name;
            }
        }

        if ($stalled !== []) {
            return Result::make()->failed('Waiting room admission has stalled for: '.implode(', ', $stalled));
        }

        return Result::make()->ok('Waiting room admission is current.');
    }
}

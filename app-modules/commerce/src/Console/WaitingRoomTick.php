<?php

declare(strict_types=1);

namespace Konventix\Commerce\Console;

use Illuminate\Console\Command;
use Konventix\Commerce\Jobs\AdmitWaitingRoomBatch;
use Konventix\Commerce\WaitingRoom\WaitingRoomStore;
use Konventix\Platform\Models\Brand;
use Laravel\Pennant\Feature;

/**
 * The waiting room's own heartbeat (ADR-0047 point 5, Task 13). Scheduled
 * `everyMinute()->withoutOverlapping()` (`CommerceServiceProvider::
 * registerSchedule()`), beside the cart sweep. For every brand with
 * `commerce:waiting-room` active and something actually queued, dispatches
 * twelve `AdmitWaitingRoomBatch` jobs five seconds apart — one minute's
 * worth of staggered admission — rather than one lump sum, so the queue
 * drains smoothly and Horizon's `waiting-room` supervisor stays busy for
 * the whole minute instead of bursting once and idling.
 *
 * No `BrandContext` involved — this runs outside any request, so it walks
 * every brand explicitly instead of asking for "the current one" (there
 * is none).
 */
final class WaitingRoomTick extends Command
{
    private const BATCHES_PER_TICK = 12;

    private const BATCH_INTERVAL_SECONDS = 5;

    protected $signature = 'commerce:waiting-room-tick';

    protected $description = 'Dispatch staggered admission batches for every brand with a busy waiting room (Commerce).';

    public function handle(WaitingRoomStore $store): int
    {
        $dispatched = 0;

        foreach (Brand::query()->get() as $brand) {
            if (! Feature::for($brand)->active('commerce:waiting-room')) {
                continue;
            }

            if ($store->depth($brand->getKey()) <= 0) {
                continue;
            }

            for ($i = 0; $i < self::BATCHES_PER_TICK; $i++) {
                AdmitWaitingRoomBatch::dispatch($brand->getKey())
                    ->delay(now()->addSeconds(self::BATCH_INTERVAL_SECONDS * $i));
            }

            $dispatched++;
        }

        $this->components->info("Dispatched admission batches for {$dispatched} brand(s).");

        return self::SUCCESS;
    }
}

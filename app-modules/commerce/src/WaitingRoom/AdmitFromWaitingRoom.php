<?php

declare(strict_types=1);

namespace Konventix\Commerce\WaitingRoom;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Konventix\Commerce\Models\WaitingRoomSettings;
use Konventix\Platform\Models\Brand;

/**
 * Admits up to `$count` people from the head of a brand's queue (ADR-0047
 * point 5). The Redis `ZPOPMIN` behind `admitNext()` is already atomic, but
 * "pop, then stamp `admitted_at`/`expires_at`" is two calls — the lock
 * closes the gap so two overlapping ticks (a scheduler run and a manual
 * one, say) cannot both believe they admitted the same batch.
 *
 * Callers decide `$count` — this task builds the mechanism, not its
 * schedule; Task 13 is what calls this on a tick, reading
 * `WaitingRoomSettings::forBrand()->admit_per_interval` for `$count`
 * itself. `admission_ttl_minutes` is read here regardless, since the
 * expiry stamped on each admitted ticket is this Action's own job.
 */
final class AdmitFromWaitingRoom
{
    public function __construct(private readonly WaitingRoomStore $store) {}

    public function handle(Brand $brand, int $count): int
    {
        $brandId = $brand->getKey();

        return Cache::lock("waiting-room:{$brandId}", 10)->block(2, function () use ($brandId, $count): int {
            $settings = WaitingRoomSettings::forBrand($brandId);
            $admittedAt = CarbonImmutable::now();
            $expiresAt = $admittedAt->addMinutes($settings->admission_ttl_minutes);

            $ticketIds = $this->store->admitNext($brandId, $count);

            foreach ($ticketIds as $ticketId) {
                $this->store->markAdmitted($brandId, $ticketId, $admittedAt, $expiresAt);
            }

            return count($ticketIds);
        });
    }
}

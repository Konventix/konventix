<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Models\ResourceBooking;

/**
 * The hold sweep (design doc §9.7, SPEC.md "Hold expiry"): every `held`
 * booking whose `hold_expires_at` has passed becomes `expired` through
 * `ReleaseResourceBooking`, which frees its slot and emits
 * `ResourceBookingReleased` with reason `expired`.
 *
 * Two kinds of hold exist (ADR-0060): clocked direct holds, which this sweep
 * ends, and registration seat bookings, which are confirmed in the same
 * transaction as their hold and therefore never appear here. A hold with no
 * clock (`hold_expires_at` null) is never selected.
 *
 * Runs with no brand in context (scheduler), so every release happens with
 * the booking's own brand set through `BrandContext::withBrand()` — the
 * listeners downstream read the ambient brand — and the context is
 * restored afterwards.
 *
 * The last run is written to the cache under `LAST_RUN_CACHE_KEY` on every
 * run, including empty ones. The S6 gauge
 * `resource_hold_sweep_last_run_timestamp_seconds` reads it through
 * `lastRunAt()`; the cache is Redis on the cluster, so the web role that
 * answers `/metrics` sees what the scheduler role wrote. A process-static
 * would not survive that boundary.
 */
final class ExpireResourceHolds
{
    public const string LAST_RUN_CACHE_KEY = 'resources:expire-holds:last-run-at';

    public const string REASON_EXPIRED = 'expired';

    private const int CHUNK = 200;

    public function __construct(
        private readonly ReleaseResourceBooking $release,
    ) {}

    /**
     * @return int the number of holds expired
     */
    public function handle(): int
    {
        $expired = 0;

        ResourceBooking::withoutBrandScope()
            ->where('state', BookingState::Held)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<', now())
            ->chunkById(self::CHUNK, function (Collection $bookings) use (&$expired): void {
                /** @var ResourceBooking $booking */
                foreach ($bookings as $booking) {
                    $brand = Brand::query()->withoutGlobalScopes()->findOrFail($booking->brand_id);

                    app(BrandContext::class)->withBrand(
                        $brand,
                        fn (): ResourceBooking => $this->release->handle($booking, BookingState::Expired, self::REASON_EXPIRED),
                    );

                    $expired++;
                }
            });

        Cache::forever(self::LAST_RUN_CACHE_KEY, now()->toIso8601String());

        return $expired;
    }

    /**
     * When the sweep last completed, on any role — null before the first run.
     */
    public static function lastRunAt(): ?CarbonImmutable
    {
        $value = Cache::get(self::LAST_RUN_CACHE_KEY);

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}

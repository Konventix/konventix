<?php

declare(strict_types=1);

namespace Konventix\Commerce\Jobs;

use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Konventix\Commerce\Models\WaitingRoomSettings;
use Konventix\Commerce\WaitingRoom\AdmitFromWaitingRoom;
use Konventix\Commerce\WaitingRoom\WaitingRoomStore;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Observability\Metrics;
use Laravel\Pennant\Feature;
use Laravel\Pulse\Facades\Pulse;

/**
 * One admission batch for one brand (ADR-0047 point 5, Task 13) — what
 * `WaitingRoomTick` dispatches twelve times a minute, staggered five
 * seconds apart, so a busy queue drains smoothly instead of in one lump.
 * Queue `waiting-room` (Horizon's own supervisor, `config/horizon.php`),
 * separate from `default` so a rush admitting a thousand arrivals never
 * starves whatever else is queued.
 *
 * `uniqueId()` deliberately does NOT collapse to the brand id alone.
 * Laravel checks a `ShouldBeUnique` job's lock at *dispatch* time
 * (`Illuminate\Foundation\Bus\PendingDispatch::shouldDispatch()`), before
 * any `->delay()` offset has actually elapsed — so if every one of the
 * twelve same-tick dispatches shared one key, the first would acquire the
 * lock and the other eleven would silently fail to queue at all (verified
 * against this app's own Laravel version: a tight dispatch loop keyed on
 * brand id alone pushed exactly one of twelve). Folding the batch's own
 * delay into the key gives each of the twelve its own slot, while
 * `uniqueFor` still does real work: a genuine duplicate dispatch for the
 * same brand *and* the same slot — a scheduler hiccup, a manual retry —
 * collapses to one push, same as the brief's stated shape intends.
 */
final class AdmitWaitingRoomBatch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 5;

    public function __construct(public readonly string $brandId)
    {
        $this->onQueue('waiting-room');
    }

    public function uniqueId(): string
    {
        $slot = match (true) {
            $this->delay instanceof DateTimeInterface => (string) $this->delay->getTimestamp(),
            is_int($this->delay) => (string) $this->delay,
            default => '0',
        };

        return $this->brandId.':'.$slot;
    }

    public function handle(AdmitFromWaitingRoom $admit, WaitingRoomStore $store): void
    {
        $brand = Brand::query()->find($this->brandId);

        if ($brand === null || ! Feature::for($brand)->active('commerce:waiting-room')) {
            return;
        }

        $settings = WaitingRoomSettings::forBrand($this->brandId);
        $admitted = $admit->handle($brand, $settings->admit_per_interval);

        // Written whether or not anyone was admitted — WaitingRoomCheck's
        // whole signal is "the tick is still ticking", not "the queue is
        // still busy".
        $store->recordAdmissionRun($this->brandId);

        if ($admitted > 0) {
            Pulse::record('waiting_room_admitted', $this->brandId, $admitted)->sum();

            app(Metrics::class)->increment('waiting_room_admissions_total', ['brand' => BrandSlugs::of($this->brandId)], (float) $admitted);
        }
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Resources\Metrics;

use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Models\Brand;
use Konventix\Resources\Actions\ExpireResourceHolds;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Models\ResourceClaim;
use Konventix\Resources\Models\ResourceEventWindow;
use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;

/**
 * Scrape-time gauges (ADR-0059). Each closure runs when /metrics is
 * rendered, outside any brand context, so the claim query drops the brand
 * scope and groups by brand itself.
 *
 * The unplaced-claims gauge is the number the operator watches instead of
 * a category-versus-quota validation Resources cannot perform (design §12):
 * a confirmed registration that cannot find a free place in its categories
 * stays `unplaced`, and this is where that shows.
 *
 * It carries `event` as well as `brand` (2026-09-10 batch amendment, ahead
 * of design §12b's quota decision): "eleven claims with no seat" tells an
 * operator nothing about which event is short of places, and once the
 * picker is open an unplaced claim means someone paid and cannot sit
 * down — a business signal, not a queue depth to average across every
 * event a brand runs. The event slug comes from this module's own
 * `resource_event_windows` projection (ADR-0004: never Event's models); a
 * claim whose window has not been projected, or carries no slug yet,
 * labels as `unknown` rather than dropping the row.
 *
 * The heartbeat has no row until `resources:expire-holds` has run once, so
 * an instance whose brands never switched Resources on emits no series and
 * KonventixResourceHoldSweepStale stays quiet there.
 */
final class ResourcesGauges implements Collector
{
    public function register(): void
    {
        Prometheus::addGauge('Seat claims unplaced')
            ->name('resource_seat_claims_unplaced')
            ->label('brand')
            ->label('event')
            ->helpText('Seat claims whose registrant has not picked a place yet, by event.')
            ->value(function (): array {
                $slugs = Brand::query()->pluck('slug', 'id');
                $eventSlugs = ResourceEventWindow::withoutBrandScope()->pluck('slug', 'event_id');

                return ResourceClaim::withoutBrandScope()
                    ->where('state', ClaimState::Unplaced)
                    ->selectRaw('brand_id, event_id, count(*) as unplaced')
                    ->groupBy('brand_id', 'event_id')
                    ->get()
                    ->map(fn (ResourceClaim $row): array => [
                        (int) $row->getAttribute('unplaced'),
                        [
                            (string) ($slugs[$row->brand_id] ?? BrandSlugs::UNKNOWN),
                            (string) ($eventSlugs[$row->event_id] ?? BrandSlugs::UNKNOWN),
                        ],
                    ])
                    ->all();
            });

        Prometheus::addGauge('Hold sweep heartbeat')
            ->name('resource_hold_sweep_last_run_timestamp_seconds')
            ->helpText('When resources:expire-holds last finished a run.')
            ->value(function (): array {
                $at = ExpireResourceHolds::lastRunAt();

                return $at === null ? [] : [[(float) $at->getTimestamp(), []]];
            });
    }
}

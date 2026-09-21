<?php

declare(strict_types=1);

namespace Konventix\Support\Metrics;

use Konventix\Platform\Models\Brand;
use Konventix\Support\Data\SupportContextData;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Models\SupportRequest;
use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;

/**
 * Scrape-time gauges (ADR-0059, design §10.6). Each closure runs when
 * `/metrics` is rendered, outside any brand context.
 *
 * No `withoutBrandScope()` is needed here and its absence is deliberate:
 * `SupportRequest` carries no `BelongsToBrand`, so these queries already
 * see every brand's rows plus the console-filed ones. The grouping by brand
 * is done here, the same way Commerce's gauges do it, because
 * `filed_on_brand_id` is a plain column.
 *
 * **Every brand gets a row, zero included** — the lesson `CommerceGauges`
 * paid for: the registry only ever *sets* the series a closure returns, so
 * a gauge that reports a brand only while it has something waiting leaves
 * the last non-zero value standing forever after the queue is cleared. The
 * console row is always emitted for the same reason.
 *
 * The unseen-age gauge reports `0` rather than staying absent when nothing
 * is waiting, so the series exists from the first scrape and
 * `KonventixSupportUnseen` has something to evaluate instead of a gap.
 */
final class SupportGauges implements Collector
{
    public function register(): void
    {
        Prometheus::addGauge('Support requests open')
            ->name('support_requests_open')
            ->label('brand')
            ->helpText('Support reports still waiting: received, seen or in progress.')
            ->value(function (): array {
                $open = SupportRequest::query()
                    ->open()
                    ->whereNotNull('filed_on_brand_id')
                    ->selectRaw('filed_on_brand_id, count(*) as waiting')
                    ->groupBy('filed_on_brand_id')
                    ->pluck('waiting', 'filed_on_brand_id');

                $rows = [];

                foreach (Brand::query()->pluck('slug', 'id') as $brandId => $slug) {
                    $rows[] = [(float) ($open[$brandId] ?? 0), [(string) $slug]];
                }

                // The operator console files reports under no brand at all
                // (ADR-0063). Counted separately rather than grouped, since
                // a null grouping key is not a brand id and would have to be
                // fished back out of the keyed result.
                $rows[] = [
                    (float) SupportRequest::query()->open()->whereNull('filed_on_brand_id')->count(),
                    [SupportContextData::CONSOLE_BRAND],
                ];

                return $rows;
            });

        Prometheus::addGauge('Support oldest unseen age')
            ->name('support_requests_unseen_oldest_age_seconds')
            ->helpText('Age of the oldest support report nobody has opened yet; 0 when none is waiting.')
            ->value(function (): float {
                $oldest = SupportRequest::query()
                    ->where('state', SupportRequestState::Received)
                    ->min('created_at');

                if ($oldest === null) {
                    return 0.0;
                }

                return (float) max(0, now()->getTimestamp() - strtotime((string) $oldest));
            });
    }
}

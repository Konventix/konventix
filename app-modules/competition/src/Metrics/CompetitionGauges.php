<?php

declare(strict_types=1);

namespace Konventix\Competition\Metrics;

use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\ScoreEntry;
use Konventix\Competition\Models\Stage;
use Konventix\Platform\Models\Brand;
use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;

/**
 * Scrape-time gauges (ADR-0059, design §13). Each closure runs when
 * `/metrics` is rendered, outside any brand context, so every query escapes
 * `BrandScope` (subqueries included) and groups by brand itself
 * (`.ai/rules/observability.md`).
 *
 * **Every brand gets a row, zero included.** The registry only ever sets
 * the series a closure returns, so a gauge that reported a brand only while
 * it had an open dispute would leave the last non-zero value standing after
 * the desk resolved it. That is the lesson `CommerceGauges` paid for and
 * `SupportGauges` repeats, and here it would keep
 * `KonventixCompetitionDisputeWaiting` firing forever.
 */
final class CompetitionGauges implements Collector
{
    public function register(): void
    {
        Prometheus::addGauge('Competition disputes open')
            ->name('competition_disputes_open')
            ->label('brand')
            ->helpText('Disputed tournament matches the desk has not decided, in running competitions.')
            ->value(fn (): array => $this->perBrand(
                CompetitionMatch::withoutBrandScope()
                    ->whereNotNull('disputed_at')
                    ->whereNull('dispute_resolved_at')
                    ->whereIn('competition_id', Competition::withoutBrandScope()
                        ->select('id')
                        ->where('status', CompetitionStatus::Running->value))
                    ->selectRaw('brand_id, count(*) as aggregate')
                    ->groupBy('brand_id')
                    ->pluck('aggregate', 'brand_id')
                    ->all(),
            ));

        Prometheus::addGauge('Competition score entries unverified')
            ->name('competition_score_entries_unverified')
            ->label('brand')
            ->helpText('Leaderboard score entries nobody has verified or rejected, in competitions that are not over.')
            ->value(fn (): array => $this->perBrand(
                ScoreEntry::withoutBrandScope()
                    ->whereNull('verified_at')
                    ->whereNull('rejected_at')
                    ->whereIn('stage_id', Stage::withoutBrandScope()
                        ->select('id')
                        ->whereIn('competition_id', Competition::withoutBrandScope()
                            ->select('id')
                            ->whereNotIn('status', [CompetitionStatus::Finished->value, CompetitionStatus::Cancelled->value])))
                    ->selectRaw('brand_id, count(*) as aggregate')
                    ->groupBy('brand_id')
                    ->pluck('aggregate', 'brand_id')
                    ->all(),
            ));
    }

    /**
     * One row per brand, zero where the count found nothing.
     *
     * @param  array<array-key, mixed>  $counts  brand ULID => count
     * @return list<array{0: float, 1: list<string>}>
     */
    private function perBrand(array $counts): array
    {
        $rows = [];

        foreach (Brand::query()->pluck('slug', 'id') as $brandId => $slug) {
            $rows[] = [(float) ($counts[$brandId] ?? 0), [(string) $slug]];
        }

        return $rows;
    }
}

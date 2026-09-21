<?php

declare(strict_types=1);

namespace Konventix\Messaging\Metrics;

use Konventix\Messaging\Mail\MailQuota;
use Konventix\Platform\Models\Brand;
use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;

/**
 * Scrape-time gauges (ADR-0059), evaluated outside any brand context.
 *
 * Every brand gets a row on every scrape, zero included — the registry
 * only ever *sets* the rows a closure returns, and a row emitted only
 * while something is counted would leave its last value standing after
 * the window rolled over (Commerce's `payments_pending` learned this the
 * hard way, 2026-09-13). Brands on the default mailer report the shared
 * count each, which is honest: that is the number the provider holds
 * against every one of them.
 */
final class MessagingGauges implements Collector
{
    public function register(): void
    {
        Prometheus::addGauge('Mail quota used')
            ->name('mail_quota_used')
            ->label('brand')
            ->label('window')
            ->helpText('Mail sent in the current window (hour, day) against the brand\'s quota; brands on the default mailer share one count.')
            ->value(fn (): array => $this->perBrandAndWindow(
                fn (array $usage, int $perHour, int $perDay, string $window): int => $usage[$window],
            ));

        Prometheus::addGauge('Mail quota limit')
            ->name('mail_quota_limit')
            ->label('brand')
            ->label('window')
            ->helpText('The brand\'s sending quota for the window (hour, day).')
            ->value(fn (): array => $this->perBrandAndWindow(
                fn (array $usage, int $perHour, int $perDay, string $window): int => $window === MailQuota::WINDOW_HOUR ? $perHour : $perDay,
            ));
    }

    /**
     * @param  callable(array{hour: int, day: int}, int, int, string): int  $value
     * @return list<array{int, list<string>}>
     */
    private function perBrandAndWindow(callable $value): array
    {
        $quota = app(MailQuota::class);
        $rows = [];

        foreach (Brand::query()->orderBy('slug')->get(['id', 'slug']) as $brand) {
            $scope = $quota->scopeFor((string) $brand->getKey());
            $usage = $quota->usage($scope);

            foreach ([MailQuota::WINDOW_HOUR, MailQuota::WINDOW_DAY] as $window) {
                $rows[] = [$value($usage, $scope->perHour, $scope->perDay, $window), [(string) $brand->slug, $window]];
            }
        }

        return $rows;
    }
}

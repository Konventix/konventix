<?php

declare(strict_types=1);

namespace Konventix\Commerce\Metrics;

use Illuminate\Support\Facades\Cache;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\WaitingRoom\WaitingRoomStore;
use Konventix\Platform\Models\Brand;
use Laravel\Pennant\Feature;
use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;

/**
 * Scrape-time gauges (ADR-0059). Each closure runs when /metrics is
 * rendered, outside any brand context, so queries drop the brand scope and
 * group by brand themselves.
 *
 * The webhook timestamp is the one gauge with memory: the middleware writes
 * the last arrival per provider into the cache, and a provider that has not
 * been heard from since the deploy reports the moment of the first scrape
 * (`Cache::add`, set once, never overwritten). Without that seed a fresh
 * deploy with pending payments would trip KonventixWebhookSilence at once.
 */
final class CommerceGauges implements Collector
{
    public function register(): void
    {
        Prometheus::addGauge('Payments pending')
            ->name('payments_pending')
            ->label('brand')
            ->helpText('Orders still awaiting payment.')
            ->value(function (): array {
                $slugs = Brand::query()->pluck('slug', 'id');

                return Order::withoutBrandScope()
                    ->where('status', OrderStatus::Pending)
                    ->selectRaw('brand_id, count(*) as pending')
                    ->groupBy('brand_id')
                    ->get()
                    ->map(fn (Order $row): array => [(int) $row->getAttribute('pending'), [(string) ($slugs[$row->brand_id] ?? 'unknown')]])
                    ->all();
            });

        Prometheus::addGauge('Waiting room depth')
            ->name('waiting_room_depth')
            ->label('brand')
            ->helpText('Tickets queued in the waiting room.')
            ->value(fn (): array => $this->perWaitingRoomBrand(
                fn (WaitingRoomStore $store, Brand $brand): float => (float) $store->depth($brand->getKey()),
            ));

        Prometheus::addGauge('Waiting room last admission')
            ->name('waiting_room_last_admission_timestamp_seconds')
            ->label('brand')
            ->helpText('When the admission tick last ran for the brand.')
            ->value(fn (): array => $this->perWaitingRoomBrand(
                function (WaitingRoomStore $store, Brand $brand): ?float {
                    $timestamp = $store->lastAdmissionAt($brand->getKey())?->getTimestamp();

                    return $timestamp === null ? null : (float) $timestamp;
                },
            ));

        Prometheus::addGauge('Webhook last received')
            ->name('payment_webhook_last_received_timestamp_seconds')
            ->label('provider')
            ->helpText('When a webhook from the provider last arrived; seeded with the first scrape after deploy.')
            ->value(function (): array {
                Cache::add(CommerceMetrics::WEBHOOK_SEEDED_KEY, now()->getTimestamp());
                $seededAt = (int) Cache::get(CommerceMetrics::WEBHOOK_SEEDED_KEY);

                $rows = [];

                foreach (CommerceMetrics::WEBHOOK_PROVIDERS as $provider) {
                    $rows[] = [(float) (Cache::get(CommerceMetrics::webhookReceivedKey($provider)) ?? $seededAt), [$provider]];
                }

                return $rows;
            });
    }

    /**
     * @param  callable(WaitingRoomStore, Brand): ?float  $value
     * @return list<array{float, list<string>}>
     */
    private function perWaitingRoomBrand(callable $value): array
    {
        $store = app(WaitingRoomStore::class);
        $rows = [];

        foreach (Brand::query()->get() as $brand) {
            if (! Feature::for($brand)->active('commerce:waiting-room')) {
                continue;
            }

            $v = $value($store, $brand);

            if ($v !== null) {
                $rows[] = [$v, [(string) $brand->slug]];
            }
        }

        return $rows;
    }
}

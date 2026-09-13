<?php

declare(strict_types=1);

namespace Konventix\Commerce\Metrics;

use Illuminate\Support\Facades\Cache;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Stripe\StripeCredentials;
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
 * **Every label set a gauge can carry is written on every scrape.** The
 * registry only ever *sets* the rows a closure returns — the Redis storage
 * (`config/prometheus.php`) keeps a series until something overwrites it,
 * and the in-memory one behaves the same within a process. A closure that
 * returns rows only for brands with something to report therefore leaves
 * the last non-zero value standing after the condition has passed: on dev,
 * `payments_pending{brand="sxlan"} 1` outlived the pending order by two
 * days and held KonventixWebhookSilence lit against an empty table
 * (2026-09-13). So `payments_pending` reports every brand, `0` included,
 * and the waiting-room gauges report every brand the feature is on for.
 * A brand that is deleted or has the feature switched off still leaves
 * its last row behind; that is the one case this does not cover, and it
 * is noted in TODO.md.
 *
 * The webhook timestamp is the one gauge with memory: the middleware writes
 * the last arrival per provider into the cache, and a provider that has not
 * been heard from since the deploy reports the moment of the first scrape
 * (`Cache::add`, set once, never overwritten). Without that seed a fresh
 * deploy with pending payments would trip KonventixWebhookSilence at once.
 *
 * Only a *configured* provider gets a row. `CommerceMetrics::WEBHOOK_PROVIDERS`
 * is the universe every alert and dashboard is written against, but on dev
 * (and on any instance that only takes one PSP) only Stripe exists — a row
 * for `mollie`/`unzer` would seed a timestamp for a provider that will never
 * send a webhook, and once any payment sits pending long enough that seeded
 * age alone trips KonventixWebhookSilence forever. Stripe is
 * instance-level, so "configured" is `StripeCredentials::configured()`
 * (ADR-0058); Mollie and Unzer are per-brand, so "configured" is "at least
 * one `SettlementAccount` row exists for that provider" — not whether it is
 * live or connected, since even a disconnected row means the provider was
 * wired up and could still receive a late webhook.
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
                $pending = Order::withoutBrandScope()
                    ->where('status', OrderStatus::Pending)
                    ->selectRaw('brand_id, count(*) as pending')
                    ->groupBy('brand_id')
                    ->pluck('pending', 'brand_id');

                // One row per brand, zero included: a row that is only
                // emitted while something is pending is never reset once
                // nothing is (class docblock).
                return Brand::query()
                    ->pluck('slug', 'id')
                    ->map(fn (string $slug, string $brandId): array => [(int) ($pending[$brandId] ?? 0), [$slug]])
                    ->values()
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

                    // Null until the first tick: a zero here would read as
                    // 1970 and trip KonventixWaitingRoomStuck on every
                    // brand that merely switched the feature on. The row
                    // stays absent, which is safe — absence cannot go stale.
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

                foreach ($this->configuredWebhookProviders() as $provider) {
                    $rows[] = [(float) (Cache::get(CommerceMetrics::webhookReceivedKey($provider)) ?? $seededAt), [$provider]];
                }

                return $rows;
            });
    }

    /**
     * The subset of `CommerceMetrics::WEBHOOK_PROVIDERS` this instance
     * actually has wired up, so a provider nobody configured never gets a
     * silence alert.
     *
     * @return list<string>
     */
    private function configuredWebhookProviders(): array
    {
        $configuredAccountProviders = SettlementAccount::withoutBrandScope()
            ->whereIn('provider', CommerceMetrics::WEBHOOK_PROVIDERS)
            ->distinct()
            ->pluck('provider')
            ->all();

        return array_values(array_filter(
            CommerceMetrics::WEBHOOK_PROVIDERS,
            fn (string $provider): bool => $provider === 'stripe'
                ? app(StripeCredentials::class)->configured()
                : in_array($provider, $configuredAccountProviders, true),
        ));
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

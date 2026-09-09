<?php

declare(strict_types=1);

namespace Konventix\Commerce\Listeners\Metrics;

use Konventix\Commerce\Events\OrderCancelled;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\OrderPlaced;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;

/**
 * One listener for the three order outcomes (ADR-0059). Money is counted
 * as cents per brand and currency, never per person (#111's boundary).
 */
final class RecordOrderMetrics
{
    public function handle(OrderPlaced|OrderPaid|OrderCancelled $event): void
    {
        $metrics = app(Metrics::class);
        $brand = BrandSlugs::of($event->brandId);

        $outcome = match ($event::class) {
            OrderPlaced::class => 'placed',
            OrderPaid::class => 'paid',
            OrderCancelled::class => 'cancelled',
        };

        $metrics->increment('orders_total', ['brand' => $brand, 'outcome' => $outcome]);

        if ($event instanceof OrderPaid) {
            $metrics->increment('order_paid_amount_cents_total', ['brand' => $brand, 'currency' => $event->currency], (float) round(((float) $event->amountPaid) * 100));
        }
    }
}

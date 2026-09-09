<?php

declare(strict_types=1);

namespace Konventix\Commerce\Listeners\Metrics;

use Konventix\Commerce\Events\CartExpired;
use Konventix\Commerce\Events\StockExhausted;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;

final class RecordCartMetrics
{
    public function handle(CartExpired|StockExhausted $event): void
    {
        app(Metrics::class)->increment(
            $event instanceof CartExpired ? 'carts_expired_total' : 'stock_exhausted_total',
            ['brand' => BrandSlugs::of($event->brandId)],
        );
    }
}

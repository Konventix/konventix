<?php

declare(strict_types=1);

namespace Konventix\Commerce\Listeners\Metrics;

use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Events\PaymentReceived;
use Konventix\Commerce\Events\PaymentReversalRecorded;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;

final class RecordPaymentMetrics
{
    public function handle(PaymentReceived|PaymentFailed|PaymentReversalRecorded $event): void
    {
        [$provider, $outcome] = match ($event::class) {
            PaymentReceived::class => [$event->settlementMethod, 'received'],
            PaymentFailed::class => [$event->settlementMethod, 'failed'],
            PaymentReversalRecorded::class => [$event->provider, 'reversed'],
        };

        app(Metrics::class)->increment('payments_total', [
            'brand' => BrandSlugs::of($event->brandId),
            'provider' => $provider,
            'outcome' => $outcome,
        ]);
    }
}

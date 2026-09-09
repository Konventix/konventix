<?php

declare(strict_types=1);

namespace Konventix\Commerce\Metrics;

use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;

/**
 * Commerce's entries in the metric catalogue (ADR-0059). Counters are
 * incremented by the listeners in src/Listeners/Metrics and the webhook
 * middleware; gauges are answered by CommerceGauges at scrape time.
 */
final class CommerceMetrics
{
    public const array WEBHOOK_PROVIDERS = ['stripe', 'mollie', 'unzer'];

    public const string WEBHOOK_SEEDED_KEY = 'commerce:metrics:webhook-seeded-at';

    public static function webhookReceivedKey(string $provider): string
    {
        return 'commerce:metrics:webhook-received:'.$provider;
    }

    /** @return list<MetricDefinition> */
    public static function definitions(): array
    {
        return [
            new MetricDefinition('orders_total', MetricKind::Counter, 'Orders by outcome: placed, paid, cancelled.', ['brand', 'outcome']),
            new MetricDefinition('order_paid_amount_cents_total', MetricKind::Counter, 'Gross amount of paid orders, in cents, per brand and currency.', ['brand', 'currency']),
            new MetricDefinition('payments_total', MetricKind::Counter, 'Payment events by provider and outcome: received, failed, reversed.', ['brand', 'provider', 'outcome']),
            new MetricDefinition('carts_expired_total', MetricKind::Counter, 'Carts the expiry sweep closed.', ['brand']),
            new MetricDefinition('stock_exhausted_total', MetricKind::Counter, 'Variants that ran out while someone was buying.', ['brand']),
            new MetricDefinition('waiting_room_admissions_total', MetricKind::Counter, 'Tickets admitted from the waiting room.', ['brand']),
            new MetricDefinition('payment_webhooks_total', MetricKind::Counter, 'PSP webhooks by provider and outcome: accepted (2xx), rejected (4xx), error (5xx).', ['provider', 'outcome']),
            new MetricDefinition('payment_webhook_duration_seconds', MetricKind::Histogram, 'Time to answer a PSP webhook.', ['provider'], [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10]),
            new MetricDefinition('payments_pending', MetricKind::Gauge, 'Orders still awaiting payment.', ['brand']),
            new MetricDefinition('waiting_room_depth', MetricKind::Gauge, 'Tickets queued in the waiting room.', ['brand']),
            new MetricDefinition('waiting_room_last_admission_timestamp_seconds', MetricKind::Gauge, 'When the admission tick last ran for the brand.', ['brand']),
            new MetricDefinition('payment_webhook_last_received_timestamp_seconds', MetricKind::Gauge, 'When a webhook from the provider last arrived; seeded with the first scrape after deploy.', ['provider']),
        ];
    }
}

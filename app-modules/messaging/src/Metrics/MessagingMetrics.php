<?php

declare(strict_types=1);

namespace Konventix\Messaging\Metrics;

use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;

/**
 * Messaging's entries in the metric catalogue (ADR-0059). `messages_total`
 * is incremented by `Listeners\Metrics\RecordMessageMetrics` from the
 * module's own delivery events; `mail_quota_held_total` by `Mail\MailQuota`
 * where a hold is decided; the two quota gauges are answered by
 * `MessagingGauges` at scrape time.
 */
final class MessagingMetrics
{
    /** @return list<MetricDefinition> */
    public static function definitions(): array
    {
        return [
            new MetricDefinition('messages_total', MetricKind::Counter, 'Message deliveries by channel and outcome: delivered, failed, retry, suppressed.', ['channel', 'outcome']),
            new MetricDefinition('mail_quota_held_total', MetricKind::Counter, 'Mail held because the sending quota for the window (hour, day) was exhausted.', ['brand', 'window']),
            new MetricDefinition('mail_quota_used', MetricKind::Gauge, 'Mail sent in the current window (hour, day) against the brand\'s quota; brands on the default mailer share one count.', ['brand', 'window']),
            new MetricDefinition('mail_quota_limit', MetricKind::Gauge, 'The brand\'s sending quota for the window (hour, day).', ['brand', 'window']),
        ];
    }
}

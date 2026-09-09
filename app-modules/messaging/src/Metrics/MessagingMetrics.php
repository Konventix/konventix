<?php

declare(strict_types=1);

namespace Konventix\Messaging\Metrics;

use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;

final class MessagingMetrics
{
    /** @return list<MetricDefinition> */
    public static function definitions(): array
    {
        return [
            new MetricDefinition('messages_total', MetricKind::Counter, 'Message deliveries by channel and outcome: delivered, failed, retry, suppressed.', ['channel', 'outcome']),
        ];
    }
}

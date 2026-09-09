<?php

declare(strict_types=1);

namespace Konventix\Messaging\Listeners\Metrics;

use Konventix\Messaging\Events\MessageDelivered;
use Konventix\Messaging\Events\MessageFailed;
use Konventix\Messaging\Events\MessageSuppressed;
use Konventix\Platform\Observability\Metrics;

final class RecordMessageMetrics
{
    public function handle(MessageDelivered|MessageFailed|MessageSuppressed $event): void
    {
        $outcome = match (true) {
            $event instanceof MessageDelivered => 'delivered',
            $event instanceof MessageSuppressed => 'suppressed',
            $event->willRetry => 'retry',
            default => 'failed',
        };

        app(Metrics::class)->increment('messages_total', ['channel' => $event->channel, 'outcome' => $outcome]);
    }
}

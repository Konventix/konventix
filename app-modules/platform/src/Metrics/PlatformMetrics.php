<?php

declare(strict_types=1);

namespace Konventix\Platform\Metrics;

use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;

/**
 * Platform's entries in the metric catalogue (ADR-0059). The kernel module
 * owns the observability machinery but had nothing of its own to count
 * until the live hub: `platform_hub_views_total` (D-HUB-04) is recorded by
 * `Hub\HubPage` on every render, not by a listener — a page view is an
 * HTTP fact, and no domain event exists for it. The host's own metrics
 * (requests, queues, health) stay in `ObservabilityServiceProvider`.
 */
final class PlatformMetrics
{
    /** @return list<MetricDefinition> */
    public static function definitions(): array
    {
        return [
            new MetricDefinition('platform_hub_views_total', MetricKind::Counter, 'Live-hub page renders (/live and the home switch).', ['brand']),
        ];
    }
}

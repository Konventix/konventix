<?php

declare(strict_types=1);

namespace Konventix\Event\Metrics;

use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;

/**
 * Event's entries in the metric catalogue (ADR-0059, design §9). One
 * counter today — the calendar file has no gauge or histogram — recorded
 * by `Http\Middleware\RecordCalendarDownloadMetrics` on the public
 * download route, not by a listener: a download is an HTTP fact, not a
 * domain-state change, and no event exists for it.
 */
final class EventMetrics
{
    /** @return list<MetricDefinition> */
    public static function definitions(): array
    {
        return [
            new MetricDefinition('event_calendar_downloads_total', MetricKind::Counter, 'Public .ics calendar file downloads.', ['brand']),
        ];
    }
}

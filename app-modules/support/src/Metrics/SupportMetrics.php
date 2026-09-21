<?php

declare(strict_types=1);

namespace Konventix\Support\Metrics;

use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;
use Konventix\Support\Data\SupportContextData;

/**
 * Support's entries in the metric catalogue (ADR-0059, design §10.6).
 * Counters are written by the listeners in `src/Listeners/Metrics`; the two
 * gauges are answered by {@see SupportGauges} at scrape time.
 *
 * **Every label here is a bounded set** (`.ai/rules/observability.md`):
 * `brand` is a slug or the literal `console`, `category` is one of the five
 * `SupportCategory` cases and `state` one of the five
 * `SupportRequestState` cases. The request id, the reference and anything
 * the reporter typed are deliberately not labels — a per-report series
 * would be unbounded cardinality, and the id belongs in the log record
 * (`support.request`) where it is searchable and not multiplied.
 *
 * `support_requests_total` is the series D-SUP-06 rests on: "purchase
 * affected" reaches the on-call channel as an alert on this counter, never
 * as a Messaging send on Commerce's topic key.
 */
final class SupportMetrics
{
    /** @return list<MetricDefinition> */
    public static function definitions(): array
    {
        return [
            new MetricDefinition(
                'support_requests_total',
                MetricKind::Counter,
                'Support reports filed, one increment per selected category.',
                ['brand', 'category'],
            ),
            new MetricDefinition(
                'support_request_transitions_total',
                MetricKind::Counter,
                'Support request state changes, labelled with the state moved into.',
                ['brand', 'state'],
            ),
            new MetricDefinition(
                'support_requests_open',
                MetricKind::Gauge,
                'Support reports still waiting: received, seen or in progress.',
                ['brand'],
            ),
            new MetricDefinition(
                'support_requests_unseen_oldest_age_seconds',
                MetricKind::Gauge,
                'Age of the oldest support report nobody has opened yet; 0 when none is waiting.',
            ),
        ];
    }

    /**
     * The `brand` label for a report. A console-filed report has no brand
     * (ADR-0063) and gets the literal `console`, which no slug can be —
     * so the dashboards and the alert annotation can always name where a
     * report came from without a second series.
     */
    public static function brandLabel(?string $brandId): string
    {
        return $brandId === null
            ? SupportContextData::CONSOLE_BRAND
            : BrandSlugs::of($brandId);
    }
}

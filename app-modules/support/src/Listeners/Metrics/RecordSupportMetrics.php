<?php

declare(strict_types=1);

namespace Konventix\Support\Listeners\Metrics;

use Konventix\Platform\Observability\Metrics;
use Konventix\Support\Events\SupportRequestOpened;
use Konventix\Support\Events\SupportRequestStateChanged;
use Konventix\Support\Metrics\SupportMetrics;

/**
 * Support's counters (ADR-0059), written from Support's own events.
 *
 * A report counts once **per selected category**, not once per report: a
 * person who ticks "purchase affected" and "display" is telling the
 * operator two things, and `KonventixSupportPurchaseAffected` has to see
 * the first of them whatever else was ticked beside it (D-SUP-06).
 * `sum without (category)` is the count of reports when a dashboard wants
 * that instead.
 *
 * No try/catch: `Metrics` throws only on a programmer error — an undeclared
 * name or an unknown label — and contains a storage failure itself
 * (`.ai/rules/observability.md`), so a Redis outage can never abort the
 * report it was counting.
 *
 * Nothing is resolved in a constructor: the dispatcher builds listeners
 * with the worker's base container, so a scoped binding captured here would
 * be the first request's forever (`.ai/rules/listeners.md`).
 */
final class RecordSupportMetrics
{
    public function handle(SupportRequestOpened|SupportRequestStateChanged $event): void
    {
        $metrics = app(Metrics::class);
        $brand = SupportMetrics::brandLabel($event->brandId);

        if ($event instanceof SupportRequestOpened) {
            foreach ($event->categories as $category) {
                $metrics->increment('support_requests_total', [
                    'brand' => $brand,
                    'category' => $category,
                ]);
            }

            return;
        }

        $metrics->increment('support_request_transitions_total', [
            'brand' => $brand,
            'state' => $event->to,
        ]);
    }
}

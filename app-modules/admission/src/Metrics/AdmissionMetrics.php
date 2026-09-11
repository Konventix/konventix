<?php

declare(strict_types=1);

namespace Konventix\Admission\Metrics;

use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;

/**
 * Admission's entries in the metric catalogue (ADR-0059, design doc §9).
 * Only the one this feature owns here: the ticket PDF and calendar
 * counters belong to the plan sections that render them. Incremented by
 * `Listeners\Metrics\RecordAdmissionTokenMetrics`. The `konventix-sale`
 * dashboard row for all three of this feature's metrics is a later task's
 * (design doc §9) — this class only has to be declared, or
 * `ObservabilityCoverageTest` has nothing to check that later panel
 * against.
 */
final class AdmissionMetrics
{
    /** @return list<MetricDefinition> */
    public static function definitions(): array
    {
        return [
            new MetricDefinition(
                'admission_tokens_superseded_total',
                MetricKind::Counter,
                "Scans refused because the presented token's nonce no longer matches the admission's current one.",
                ['brand'],
            ),
        ];
    }
}

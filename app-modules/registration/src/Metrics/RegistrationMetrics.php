<?php

declare(strict_types=1);

namespace Konventix\Registration\Metrics;

use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;

/**
 * Registration's first entries in the metric catalogue (ADR-0059, spec §10).
 * Both are counters, incremented by `Listeners\Metrics\
 * RecordEntryConditionMetrics` on this module's own events.
 *
 * **Verification, state and source — never the condition's key** (T-11).
 * A key is operator-defined: a brand that writes one condition per event
 * would turn a counter into thousands of series, and a dashboard nobody can
 * read is the cheapest way to lose the numbers that matter. The three labels
 * here are closed enums (`EntryConditionVerification`,
 * `EntryConditionAssertionState`, `EntryConditionDecisionSource`), which is
 * what makes them safe.
 *
 * `KonventixEntryConditionRefusalsAtDoor` reads the second one —
 * `state="refused", source="door"` — which is why the source is a label and
 * not folded into the state.
 */
final class RegistrationMetrics
{
    /** @return list<MetricDefinition> */
    public static function definitions(): array
    {
        return [
            new MetricDefinition(
                'entry_condition_assertions_total',
                MetricKind::Counter,
                'Entry-condition declarations made at registration, by how they are to be verified.',
                ['brand', 'verification'],
            ),
            new MetricDefinition(
                'entry_condition_decisions_total',
                MetricKind::Counter,
                'Decisions on entry-condition declarations, by outcome and where the decision was made.',
                ['brand', 'state', 'source'],
            ),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Registration\Metrics;

use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;

/**
 * Registration's entries in the metric catalogue (ADR-0059, spec §10). All
 * three are counters; the two entry-condition ones are incremented by
 * `Listeners\Metrics\RecordEntryConditionMetrics` on this module's own
 * events, and `confirmed_registrations_kept_total` is written straight from
 * `Actions\CancelPendingRegistrationForCart`, which is where that fact is
 * known and where nothing else needs to hear about it.
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
            /*
             * #211: the cart line holding a *confirmed* registration's
             * payment died and the registration was left standing rather
             * than voided (operator decision 2026-09-22, ADR-0057 point 5).
             * This is the operator-visible half of "logged and reported":
             * somebody holds a seat and still owes money, which is a
             * receivable to chase and not an error, so it is a counter on
             * the Sale dashboard rather than an alert.
             *
             * `trigger` is `CartHoldEnded`'s two values — a closed enum,
             * which is what makes it safe as a label (T-11).
             */
            new MetricDefinition(
                'confirmed_registrations_kept_total',
                MetricKind::Counter,
                'Confirmed registrations left standing when the cart line holding their payment died, by what ended the hold.',
                ['brand', 'trigger'],
            ),
        ];
    }
}

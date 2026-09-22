<?php

declare(strict_types=1);

namespace Konventix\Agenda\Metrics;

use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;

/**
 * Agenda's entries in the metric catalogue (ADR-0059, design §8). One
 * counter and nothing else: the only operational state a programme has is
 * "did anything become public", and that is a count that moves, not a
 * queue, a heartbeat or an external call.
 *
 * No alert goes with it, deliberately (design §8): a programme nobody has
 * published yet is a planning state — most of an event's life is spent
 * exactly there — and an alert on it would fire on every brand that is
 * simply not finished planning.
 *
 * Recorded by `Listeners\Metrics\RecordAgendaMetrics` on this module's own
 * `AgendaItemPublished`, which `PublishAgendaItem` emits once per item and
 * never for a re-publish, so the counter counts items becoming visible
 * rather than buttons being pressed.
 */
final class AgendaMetrics
{
    /** @return list<MetricDefinition> */
    public static function definitions(): array
    {
        return [
            new MetricDefinition('agenda_items_published_total', MetricKind::Counter, 'Programme items published.', ['brand']),
        ];
    }
}

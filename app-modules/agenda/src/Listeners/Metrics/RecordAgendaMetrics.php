<?php

declare(strict_types=1);

namespace Konventix\Agenda\Listeners\Metrics;

use Konventix\Agenda\Events\AgendaItemPublished;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;

/**
 * The recorder behind `agenda_items_published_total` (ADR-0059, design §8).
 *
 * `brand` is the only label: the question a dashboard answers is "is this
 * brand's programme filling up", and an event or a track id would multiply
 * series for a question nobody asks of a graph
 * (`.ai/rules/observability.md`).
 *
 * No try/catch: `Metrics` reports a storage failure instead of propagating
 * it, so counting an item can never abort the publish that produced it.
 * Scoped bindings are resolved inside the handler, never in a constructor
 * (`.ai/rules/listeners.md`) — under Octane the dispatcher builds this
 * class with the worker's base container, not the request's.
 */
final class RecordAgendaMetrics
{
    public function handleItemPublished(AgendaItemPublished $event): void
    {
        app(Metrics::class)->increment('agenda_items_published_total', [
            'brand' => BrandSlugs::of($event->brandId),
        ]);
    }
}

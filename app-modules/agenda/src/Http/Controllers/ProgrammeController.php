<?php

declare(strict_types=1);

namespace Konventix\Agenda\Http\Controllers;

use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Agenda\Models\EventSummary;
use Konventix\Agenda\Programme\ProgrammeBuilder;

/**
 * The public programme (design §6): one grid per event day, a day
 * switcher, a track filter.
 *
 * **No authentication and no capability, on purpose.** A programme is an
 * announcement — gating it behind an account would defeat the point of
 * publishing one (design §6). The only gate it passes is the module's own
 * per-brand activation, applied to the whole route file by
 * `ModuleServiceProvider` (ADR-0003); nothing is checked here.
 *
 * The route hands over a **slug, not a bound model**: `{event:slug}` would
 * need Event's Eloquent model, which this module may not import
 * (ADR-0004). The slug is resolved against Agenda's own read model
 * instead, whose row `Listeners\ProjectEventSummary` keeps current. An
 * event this module has never been told about has no programme to show and
 * 404s, which is the same answer it would give for a slug that names
 * nothing.
 *
 * `BrandScope` is deliberately left on: this runs on the brand's own host,
 * so the scope is exactly the right filter and two brands may hold the
 * same slug (ADR-0010).
 */
final class ProgrammeController
{
    public function __invoke(string $slug, ProgrammeBuilder $builder): Response
    {
        /** @var EventSummary $summary */
        $summary = EventSummary::query()->where('slug', $slug)->firstOrFail();

        $timezone = $summary->eventTimezone();

        return Inertia::render('agenda/Programme', [
            'event' => [
                'name' => $summary->name,
                'slug' => $slug,
                // ADR-0027: the zone the page's wall clocks are promised in,
                // printed beside them so a visitor from elsewhere can tell.
                'timezone' => $timezone,
                // Which day the switcher opens on. "Today" means today in
                // the *event's* zone, and the browser may not reach for a
                // zone database of its own (`.ai/rules/js.md`) — so the one
                // side that knows the zone answers it.
                'today' => CarbonImmutable::now($timezone)->toDateString(),
            ],
            // Days with no items at all are not in here — `ProgrammeBuilder`
            // omits them rather than offering the switcher an empty grid.
            'days' => $builder->forEvent(
                eventId: $summary->event_id,
                brandId: $summary->brand_id,
                days: $summary->programmeDays(),
                timezone: $timezone,
            ),
        ]);
    }
}

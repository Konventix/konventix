<?php

declare(strict_types=1);

namespace Konventix\Competition\Listeners;

use Konventix\Competition\Models\EventSummary;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDetailsUpdated;

/**
 * Keeps Competition's copy of an event's slug and name (design §10,
 * ADR-0004) — the same projection Agenda's and Admission's
 * `ProjectEventSummary` keep, cut down to the two facts the public
 * tournament pages resolve and head themselves with.
 *
 * Both events carry both facts, so both upsert the whole row. That also makes
 * the backfill work: `event:replay-details` re-emits `EventDetailsUpdated`
 * for every event, which creates the row of an event that predates this
 * projection.
 *
 * Deliberately **not** short-circuited on the Pennant gate, like the
 * eligibility and name projections beside it: a brand that switches
 * Competition on mid-season must find its events already here, or every
 * public tournament page would 404 until each event was edited.
 */
final class ProjectEventSummary
{
    public function handleCreated(EventCreated $event): void
    {
        $this->project($event->eventId, $event->brandId, $event->slug, $event->name);
    }

    public function handleDetailsUpdated(EventDetailsUpdated $event): void
    {
        $this->project($event->eventId, $event->brandId, $event->slug, $event->name);
    }

    /**
     * `withoutBrandScope()` and the payload's brand throughout: a contract
     * event is consumed wherever it was fired — a console command, a request
     * on the operator admin host where the scope hardens to zero rows
     * (ADR-0029) — so the brand never comes from ambient context (ADR-0010).
     */
    private function project(string $eventId, string $brandId, string $slug, string $name): void
    {
        $summary = EventSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->first() ?? new EventSummary(['event_id' => $eventId]);

        $summary->forceFill([
            'brand_id' => $brandId,
            'slug' => $slug,
            'name' => $name,
        ])->save();
    }
}

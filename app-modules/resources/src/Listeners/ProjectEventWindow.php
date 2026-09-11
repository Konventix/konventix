<?php

declare(strict_types=1);

namespace Konventix\Resources\Listeners;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Resources\Models\ResourceEventWindow;

/**
 * Keeps Resources' copy of when each event runs and what it is called
 * (ADR-0004: this module may not read Event's models, so it projects what
 * it needs).
 *
 * Event's payloads carry wall-clock strings plus an IANA zone (ADR-0027).
 * The window stores the UTC *instants* those name, because the booking
 * constraint compares `timestamp` columns holding UTC — parsing the strings
 * without the zone, as this listener did before S0, put every Berlin event
 * two hours early.
 *
 * One upsert keyed on `(brand_id, event_id)` — the table's unique index —
 * so creation, reschedule and replay converge on one row. A reschedule
 * that names its brand can create the row (the replay command's backfill
 * path); one that does not falls back to the brand of the existing window
 * and is dropped when there is none, because there is nothing to file it
 * under. Details only ever *name* a row that exists: without times there
 * is no window to create.
 *
 * Deliberately **not** short-circuited on the Pennant gate, unlike claim
 * fulfilment: a brand that switches Resources on mid-event must find the
 * window already there, or its first booking would have no interval to use.
 */
final class ProjectEventWindow
{
    public function handleCreated(EventCreated $event): void
    {
        $this->upsert($event->eventId, $event->brandId, [
            'starts_at' => $this->instant($event->startsAt, $event->timezone),
            'ends_at' => $this->instant($event->endsAt, $event->timezone),
            'name' => $event->name,
            'slug' => $event->slug,
        ]);
    }

    public function handleRescheduled(EventRescheduled $event): void
    {
        $brandId = $event->brandId ?? ResourceEventWindow::withoutBrandScope()
            ->where('event_id', $event->eventId)
            ->value('brand_id');

        if (! is_string($brandId)) {
            return;
        }

        $this->upsert($event->eventId, $brandId, [
            'starts_at' => $this->instant($event->startsAt, $event->timezone),
            'ends_at' => $this->instant($event->endsAt, $event->timezone),
        ]);
    }

    public function handleDetailsUpdated(EventDetailsUpdated $event): void
    {
        ResourceEventWindow::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('event_id', $event->eventId)
            ->update(['name' => $event->name, 'slug' => $event->slug]);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function upsert(string $eventId, string $brandId, array $values): void
    {
        // A raw upsert bypasses HasUlids and BelongsToBrand's creating hook,
        // so the id and the brand are written explicitly — brand_id from the
        // payload, never from ambient context (ADR-0010).
        ResourceEventWindow::withoutBrandScope()->upsert(
            [['id' => (string) Str::ulid(), 'brand_id' => $brandId, 'event_id' => $eventId, ...$values]],
            ['brand_id', 'event_id'],
            array_keys($values),
        );
    }

    /**
     * The wall-clock string in its zone, as a UTC instant string for a
     * `timestamp` column (ADR-0027: conversion at the read/projection
     * boundary, never inline in consuming code).
     */
    private function instant(string $wallClock, string $timezone): string
    {
        return Date::parse($wallClock, $timezone)->utc()->format('Y-m-d H:i:s');
    }
}

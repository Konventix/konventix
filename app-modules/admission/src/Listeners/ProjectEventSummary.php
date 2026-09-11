<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Konventix\Admission\Models\EventSummary;
use Konventix\Event\Events\EventCreated;

/**
 * Keeps Admission's copy of an event's name, schedule and primary venue
 * (ADR-0004: this module may not read Event's models, so it projects what
 * it needs from Event's contract events) — design doc §13, 2026-09-11
 * amendment "Admission learns event facts". Task 13 (2026-09-11) extends
 * this class with `handleDetailsUpdated()`, `handleRescheduled()` and the
 * three venue handlers; this task only wires `EventCreated`.
 *
 * `project()` already carries the full, all-nullable-past-the-identity-pair
 * signature every later handler needs, even though `handleCreated()` is the
 * only caller today — `array_filter` drops every null argument, so a call
 * that omits a field patches the row instead of blanking it. That is what
 * lets Task 13 add patch-only handlers without ever touching this method.
 */
final class ProjectEventSummary
{
    public function handleCreated(EventCreated $event): void
    {
        $this->project(
            eventId: $event->eventId,
            brandId: $event->brandId,
            name: $event->name,
            slug: $event->slug,
            startsAt: $event->startsAt,
            endsAt: $event->endsAt,
            timezone: $event->timezone,
        );
    }

    private function project(
        string $eventId,
        string $brandId,
        ?string $name = null,
        ?string $slug = null,
        ?string $startsAt = null,
        ?string $endsAt = null,
        ?string $timezone = null,
    ): void {
        $summary = EventSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->first() ?? new EventSummary(['event_id' => $eventId]);

        // brand_id from the payload, not ambient context (ADR-0010).
        $summary->forceFill(array_filter([
            'brand_id' => $brandId,
            'name' => $name,
            'slug' => $slug,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $timezone,
        ], fn (?string $value): bool => $value !== null))->save();
    }
}

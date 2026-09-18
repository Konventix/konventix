<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Konventix\Admission\Models\EventSummary;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Events\VenueAssigned;
use Konventix\Event\Events\VenueUnassigned;
use Konventix\Event\Events\VenueUpdated;

/**
 * Keeps Admission's copy of an event's name, schedule and primary venue
 * (ADR-0004) — design doc §13, 2026-09-11 amendments "Admission learns
 * event facts" and "`event:replay-details` gains a venue pass".
 *
 * `EventDetailsUpdated` carries a name and a slug but no times;
 * `EventRescheduled` carries times but no name or slug. Both call the same
 * `project()`, passing only what their own event carries — its
 * `array_filter` drops the rest, so neither call erases what the other set.
 * `handleRescheduled()` is the one handler that may also *create* the row:
 * `event:replay-details`' venue-pass backfill fires the schedule before the
 * details for an event this table has never seen, so the row must be able
 * to come into existence there, with `name`/`slug` staying null until
 * `handleDetailsUpdated()` arrives moments later in the same replay.
 *
 * The venue handlers never create — by the time any of them fires, the row
 * already exists from `handleCreated()` or `handleRescheduled()`.
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

        $this->projectBanner($event->eventId, $event->brandId, $event->bannerMediaId);
    }

    /**
     * `EventDetailsUpdated` carries no times: patches name and slug only —
     * and the banner, which unlike the rest may legitimately become null.
     */
    public function handleDetailsUpdated(EventDetailsUpdated $event): void
    {
        $this->project(
            eventId: $event->eventId,
            brandId: $event->brandId,
            name: $event->name,
            slug: $event->slug,
        );

        $this->projectBanner($event->eventId, $event->brandId, $event->bannerMediaId);
    }

    /**
     * Outside `project()` on purpose: its `array_filter` treats null as
     * "not carried", but for the banner (#292) null is a fact — the picture
     * was removed — and both events always carry the current value.
     */
    private function projectBanner(string $eventId, string $brandId, ?string $bannerMediaId): void
    {
        EventSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->update(['banner_media_id' => $bannerMediaId]);
    }

    /**
     * `EventRescheduled` carries times but no name or slug — the opposite
     * gap from `handleDetailsUpdated()`. Unlike that method this may create
     * the row: see the class docblock.
     */
    public function handleRescheduled(EventRescheduled $event): void
    {
        if ($event->brandId === null) {
            return;
        }

        $this->project(
            eventId: $event->eventId,
            brandId: $event->brandId,
            startsAt: $event->startsAt,
            endsAt: $event->endsAt,
            timezone: $event->timezone,
        );
    }

    /**
     * Only the *primary* venue matters here (design §4.2, §6.1) — a
     * non-primary assignment is a no-op. `venue_id` is kept alongside the
     * label so `handleVenueUpdated()` can find this row again when the
     * venue is renamed, and so `handleVenueUnassigned()` can tell "this was
     * our primary venue" from "some other venue was unassigned".
     */
    public function handleVenueAssigned(VenueAssigned $event): void
    {
        if (! $event->isPrimary) {
            return;
        }

        EventSummary::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('event_id', $event->eventId)
            ->update([
                'venue_id' => $event->venueId,
                'venue_label' => "{$event->name}, {$event->city}",
            ]);
    }

    /**
     * `VenueUpdated` carries no `eventId` — a venue can be the primary
     * venue of more than one event — so this patches every row currently
     * pointing at it, found by the `venue_id` `handleVenueAssigned()` left
     * behind.
     */
    public function handleVenueUpdated(VenueUpdated $event): void
    {
        EventSummary::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('venue_id', $event->venueId)
            ->update(['venue_label' => "{$event->name}, {$event->city}"]);
    }

    public function handleVenueUnassigned(VenueUnassigned $event): void
    {
        EventSummary::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('event_id', $event->eventId)
            ->where('venue_id', $event->venueId)
            ->update(['venue_id' => null, 'venue_label' => null]);
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

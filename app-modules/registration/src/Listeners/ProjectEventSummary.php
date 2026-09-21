<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Event\Events\EventAnnounced;
use Konventix\Event\Events\EventArchived;
use Konventix\Event\Events\EventAttendeeListPolicyChanged;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventPublished;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Events\VenueAssigned;
use Konventix\Event\Events\VenueUnassigned;
use Konventix\Event\Events\VenueUpdated;
use Konventix\Registration\Models\EventSummary;

/**
 * Keeps Registration's copy of which events exist and what they are called
 * (ADR-0004: this module may not read Event's models, so it projects what it
 * needs from Event's contract events).
 *
 * Creation and detail updates share one path because both payloads carry the
 * complete current name and slug — a projection that replaces rather than
 * patches converges on replay.
 *
 * Not gated on anything: Registration is a Core module and always active, and
 * a projection must stay current regardless of what consumes it later.
 *
 * `public_since` is `Event::isVisibleOnLanding()`'s rule — "has left
 * `Draft`" — and `visibility` is the second half a *listing* surface owes,
 * so that `public_since` + `visibility = public` is
 * `Event::scopeListedPublicly()` (branch review blocker 2). Both are held in
 * the only form ADR-0004 allows this module: scalars off the contract
 * events. Both
 * transitions out of `Draft` set it, and nothing clears it:
 * `EventUnpublished` is deliberately *not* projected, because
 * `UnpublishEvent` returns the event to `Announced` rather than to `Draft`,
 * so its landing page keeps resolving and keeps selling the very same
 * registration types. Clearing the column there made `/shop` and the landing
 * page disagree about one event (#240 re-review N3). `EventArchived` is the
 * transition that genuinely takes offers off both, and it has its own
 * column.
 *
 * `starts_at`/`ends_at`/`timezone` and `venue_label` (design doc §13,
 * 2026-09-11 amendment) feed the confirmation mail's calendar attachment.
 * `EventCreated` carries times and a timezone alongside name/slug, so
 * `handleCreated()` sets everything on one row through the same `project()`
 * helper. `EventRescheduled` carries times but no name or slug — the
 * opposite gap from `EventDetailsUpdated` — so `handleRescheduled()`
 * patches only those three columns and never touches `name`/`slug`, the
 * same "patch, never replace" care `handleDetailsUpdated()` already took
 * for `visibility`. Unlike Resources' `ProjectEventWindow::handleRescheduled()`
 * or this section's own `Konventix\Admission\Listeners\ProjectEventSummary::handleRescheduled()`,
 * this one never *creates* a row: Registration is a Core module
 * (`$gateable = false`), so — unlike Resources, which is Pennant-gated and
 * may be switched on for a brand mid-event, and unlike Admission's table,
 * which is brand new — every event already has a row here from
 * `handleCreated()` by the time any reschedule, real or replayed, can reach
 * it. A reschedule (or a venue event) for an event with no row yet is
 * therefore a safe no-op, not a creation path.
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
            visibility: $event->visibility,
            startsAt: $event->startsAt,
            endsAt: $event->endsAt,
            timezone: $event->timezone,
        );

        $this->projectBanner($event->eventId, $event->brandId, $event->bannerMediaId);
    }

    public function handleDetailsUpdated(EventDetailsUpdated $event): void
    {
        $this->project($event->eventId, $event->brandId, $event->name, $event->slug, $event->visibility);

        $this->projectBanner($event->eventId, $event->brandId, $event->bannerMediaId);
    }

    /**
     * Outside `project()` on purpose: its `array_filter` treats null as
     * "not carried", but for the banner (#292) null is a fact — the picture
     * was removed — and both events always carry the current value. The
     * mails read it (`RegistrationMailer`).
     */
    private function projectBanner(string $eventId, string $brandId, ?string $bannerMediaId): void
    {
        EventSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->update(['banner_media_id' => $bannerMediaId]);
    }

    /**
     * See the class docblock: patches the schedule on a row that already
     * exists, and never creates one.
     */
    public function handleRescheduled(EventRescheduled $event): void
    {
        EventSummary::withoutBrandScope()
            ->where('event_id', $event->eventId)
            ->update([
                'starts_at' => $event->startsAt,
                'ends_at' => $event->endsAt,
                'timezone' => $event->timezone,
            ]);
    }

    /**
     * Only the primary venue (design §6.1: the calendar `LOCATION` names
     * it) — a secondary assignment is a no-op. `venue_id` lets
     * `handleVenueUpdated()`/`handleVenueUnassigned()` find this row again.
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
     * `VenueUpdated` carries no `eventId` — the same venue may be the
     * primary venue of more than one event's summary — so every row
     * pointing at it is patched.
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

    /**
     * The event became publicly visible (#240). Both transitions out of
     * `Draft` count: `Event::isVisibleOnLanding()` is `state !== Draft`, so
     * an announced event's landing page resolves and its offers are real —
     * projecting only `EventPublished` would drop them off `/shop` for the
     * whole announced phase.
     *
     * Idempotent on replay: the *first* moment it went public is the fact,
     * so a second transition does not move the timestamp.
     */
    public function handleAnnounced(EventAnnounced $event): void
    {
        $this->markPublic($event->eventId, $event->brandId, $event->announcedAt);
    }

    public function handlePublished(EventPublished $event): void
    {
        $this->markPublic($event->eventId, $event->brandId, $event->publishedAt);
    }

    /**
     * An archived event keeps its row — existing registration types still
     * need a name to display — but the type editor stops offering it.
     */
    public function handleArchived(EventArchived $event): void
    {
        EventSummary::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('event_id', $event->eventId)
            ->update(['archived_at' => $event->archivedAt]);
    }

    /**
     * The per-event attendee-list switch (ADR-0064 §6), carried complete —
     * replace, never patch. Emitted at creation right after `EventCreated`,
     * so the row is already there; a policy event for an event with no row
     * is the same safe no-op as a reschedule for one.
     */
    public function handleAttendeeListPolicyChanged(EventAttendeeListPolicyChanged $event): void
    {
        EventSummary::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('event_id', $event->eventId)
            ->update(['attendee_list_visibility' => $event->attendeeListVisibility]);
    }

    private function markPublic(string $eventId, string $brandId, string $since): void
    {
        EventSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->whereNull('public_since')
            ->update(['public_since' => $since]);
    }

    /**
     * `$visibility` is nullable because it was added to the contract events
     * after they existed (branch review blocker 2): a payload without it
     * leaves whatever is already projected alone rather than blanking the
     * column, and a row that has never been told stays null — which the
     * shop gate reads as "not listable", the safe direction. `$startsAt`/
     * `$endsAt`/`$timezone` are nullable for the same reason, one level
     * later (design §13): `handleDetailsUpdated()` omits them so a details
     * update cannot blank a schedule already projected by `handleCreated()`.
     */
    private function project(
        string $eventId,
        string $brandId,
        string $name,
        string $slug,
        ?string $visibility = null,
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
            'visibility' => $visibility,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $timezone,
        ], fn (?string $value): bool => $value !== null))->save();
    }
}

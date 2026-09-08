<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Event\Events\EventAnnounced;
use Konventix\Event\Events\EventArchived;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventPublished;
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
 */
final class ProjectEventSummary
{
    public function handleCreated(EventCreated $event): void
    {
        $this->project($event->eventId, $event->brandId, $event->name, $event->slug, $event->visibility);
    }

    public function handleDetailsUpdated(EventDetailsUpdated $event): void
    {
        $this->project($event->eventId, $event->brandId, $event->name, $event->slug, $event->visibility);
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
     * shop gate reads as "not listable", the safe direction.
     */
    private function project(string $eventId, string $brandId, string $name, string $slug, ?string $visibility = null): void
    {
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
        ], fn (?string $value): bool => $value !== null))->save();
    }
}

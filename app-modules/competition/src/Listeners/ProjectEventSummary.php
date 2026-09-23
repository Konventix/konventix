<?php

declare(strict_types=1);

namespace Konventix\Competition\Listeners;

use Konventix\Competition\Models\EventSummary;
use Konventix\Event\Events\EventAnnounced;
use Konventix\Event\Events\EventArchived;
use Konventix\Event\Events\EventCancelled;
use Konventix\Event\Events\EventConcluded;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventPublished;
use Konventix\Event\Events\EventStarted;
use Konventix\Event\Events\EventUnpublished;

/**
 * Keeps Competition's copy of an event's slug, name and lifecycle state
 * (design §10, ADR-0004) — the same projection Agenda's and Admission's
 * `ProjectEventSummary` keep, cut down to the facts the public tournament
 * pages resolve by, head themselves with, and decide their visibility on.
 *
 * Creation and details both carry slug and name and upsert them. The
 * lifecycle events each *are* a state, and write only it — so none of the
 * handlers erases what another set, and the backfill works in any order:
 * `event:replay-details --with-state` re-emits `EventDetailsUpdated` and the
 * event's current lifecycle event for every event. Every handler is an
 * idempotent upsert, which is what that replay requires of it.
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
     * Every lifecycle transition Event announces, in one handler — Agenda's
     * mapping, so the two public surfaces of an event cannot disagree about
     * which state it is in. Unpublishing returns an event to `announced`,
     * `UnpublishEvent`'s own target state. `EventCreated` says nothing about
     * the state, so a new event stays null — refused, like the draft it is.
     */
    public function handleLifecycle(
        EventAnnounced|EventArchived|EventCancelled|EventConcluded|EventPublished|EventStarted|EventUnpublished $event,
    ): void {
        $this->project($event->eventId, $event->brandId, state: match ($event::class) {
            EventAnnounced::class, EventUnpublished::class => 'announced',
            EventPublished::class => 'published',
            EventStarted::class => 'running',
            EventConcluded::class => 'concluded',
            EventCancelled::class => 'cancelled',
            EventArchived::class => 'archived',
        });
    }

    /**
     * `withoutBrandScope()` and the payload's brand throughout: a contract
     * event is consumed wherever it was fired — a console command, a request
     * on the operator admin host where the scope hardens to zero rows
     * (ADR-0029) — so the brand never comes from ambient context (ADR-0010).
     */
    private function project(
        string $eventId,
        string $brandId,
        ?string $slug = null,
        ?string $name = null,
        ?string $state = null,
    ): void {
        $summary = EventSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->first() ?? new EventSummary(['event_id' => $eventId]);

        $summary->forceFill(array_filter([
            'brand_id' => $brandId,
            'slug' => $slug,
            'name' => $name,
            'state' => $state,
        ], static fn (?string $value): bool => $value !== null))->save();
    }
}

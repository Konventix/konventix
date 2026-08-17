<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Event\Events\EventArchived;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDetailsUpdated;
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
 */
final class ProjectEventSummary
{
    public function handleCreated(EventCreated $event): void
    {
        $this->project($event->eventId, $event->brandId, $event->name, $event->slug);
    }

    public function handleDetailsUpdated(EventDetailsUpdated $event): void
    {
        $this->project($event->eventId, $event->brandId, $event->name, $event->slug);
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

    private function project(string $eventId, string $brandId, string $name, string $slug): void
    {
        $summary = EventSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->first() ?? new EventSummary(['event_id' => $eventId]);

        // brand_id from the payload, not ambient context (ADR-0010).
        $summary->forceFill([
            'brand_id' => $brandId,
            'name' => $name,
            'slug' => $slug,
        ])->save();
    }
}

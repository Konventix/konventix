<?php

declare(strict_types=1);

namespace Konventix\Resources\Listeners;

use Illuminate\Support\Facades\Date;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Resources\Models\ResourceEventWindow;

/**
 * Keeps Resources' copy of when each event runs (ADR-0004: this module may
 * not read Event's models, so it projects what it needs).
 *
 * Handles creation and rescheduling with one method because both carry the
 * complete current window — a projection that replaces rather than patches
 * converges on replay.
 *
 * Deliberately **not** short-circuited on the Pennant gate, unlike claim
 * fulfilment: a brand that switches Resources on mid-event must find the
 * window already there, or its first booking would have no interval to use.
 * Projections stay current even while the feature they serve is off.
 */
final class ProjectEventWindow
{
    public function handleCreated(EventCreated $event): void
    {
        $this->project($event->eventId, $event->brandId, $event->startsAt, $event->endsAt);
    }

    public function handleRescheduled(EventRescheduled $event): void
    {
        $window = ResourceEventWindow::withoutBrandScope()
            ->where('event_id', $event->eventId)
            ->first();

        if ($window === null) {
            return;
        }

        $this->project($event->eventId, $window->brand_id, $event->startsAt, $event->endsAt);
    }

    private function project(string $eventId, string $brandId, string $startsAt, string $endsAt): void
    {
        $window = ResourceEventWindow::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->first() ?? new ResourceEventWindow(['event_id' => $eventId]);

        // brand_id from the payload, not ambient context (ADR-0010).
        $window->forceFill([
            'brand_id' => $brandId,
            'starts_at' => Date::parse($startsAt),
            'ends_at' => Date::parse($endsAt),
        ])->save();
    }
}

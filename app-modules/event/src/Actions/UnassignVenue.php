<?php

declare(strict_types=1);

namespace Konventix\Event\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Event\Events\VenueUnassigned;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\Venue;

/**
 * Detaches a venue from an event (D-EVT-15). Detaching the primary leaves
 * the event with no primary until an operator promotes another — an
 * explicit decision, not an implicit "oldest becomes primary".
 */
final class UnassignVenue
{
    public function handle(Event $event, Venue $venue): Event
    {
        return DB::transaction(function () use ($event, $venue): Event {
            $event->venues()->detach($venue->id);

            event(new VenueUnassigned(
                eventId: $event->id,
                venueId: $venue->id,
                brandId: $event->brand_id,
            ));

            return $event->refresh();
        });
    }
}

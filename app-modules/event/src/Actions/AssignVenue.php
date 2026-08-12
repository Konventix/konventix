<?php

declare(strict_types=1);

namespace Konventix\Event\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Event\Events\VenueAssigned;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\Venue;

/**
 * Attaches a venue to an event (D-EVT-15: many venues, one primary).
 * Making a venue primary demotes the previous primary — exactly one
 * primary exists whenever any assignment is primary; zero venues stays a
 * legal state (online-only).
 */
final class AssignVenue
{
    public function handle(Event $event, Venue $venue, bool $isPrimary = false): Event
    {
        return DB::transaction(function () use ($event, $venue, $isPrimary): Event {
            if ($isPrimary) {
                $event->venues()->newPivotStatement()
                    ->where('event_id', $event->id)
                    ->update(['is_primary' => false]);
            }

            $event->venues()->syncWithoutDetaching([
                $venue->id => ['is_primary' => $isPrimary, 'brand_id' => $event->brand_id],
            ]);

            event(new VenueAssigned(
                eventId: $event->id,
                venueId: $venue->id,
                brandId: $event->brand_id,
                name: $venue->name,
                city: $venue->city,
                isPrimary: $isPrimary,
            ));

            return $event->refresh();
        });
    }
}

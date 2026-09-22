<?php

declare(strict_types=1);

namespace Konventix\Agenda\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Agenda\Data\LocationData;
use Konventix\Agenda\Models\Location;

/**
 * Replaces an event's whole set of places with the one the editor posted
 * (design §4.3), exactly as `SaveTracks` does for the grid's columns: the
 * payload is the complete desired state, a row with an `id` is updated, a
 * row without one is created, and a row this event no longer names is
 * deleted.
 *
 * Keeping the id of a place that stays is the whole point of the place
 * being a row: renaming "Halle 3" renames it on every item at once, which
 * a free-text label could never do. Deleting one leaves its items with
 * `location_id = null` rather than deleting them (`nullOnDelete`); the
 * editor is expected to ask first.
 *
 * The deletions run before the writes because `agenda_locations` is unique
 * on (event_id, name), and every read escapes `BrandScope` and filters the
 * brand by hand because this runs on the operator admin host, where the
 * scope hardens to zero rows (ADR-0029).
 *
 * `venue_id` is Event's venue by ULID and without a foreign key
 * (ADR-0014) — display context, written through unverified.
 */
final class SaveLocations
{
    /**
     * @param  list<LocationData>  $locations
     */
    public function handle(string $eventId, string $brandId, array $locations): void
    {
        DB::transaction(function () use ($eventId, $brandId, $locations): void {
            $existing = Location::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('event_id', $eventId)
                ->get()
                ->keyBy('id');

            $kept = array_values(array_filter(array_map(
                static fn (LocationData $location): ?string => $location->id,
                $locations,
            )));

            foreach ($existing as $location) {
                if (! in_array($location->id, $kept, true)) {
                    $location->delete();
                }
            }

            foreach ($locations as $location) {
                $row = $location->id === null ? null : $existing->get($location->id);

                if (! $row instanceof Location) {
                    $row = new Location;
                    $row->forceFill([
                        'brand_id' => $brandId,
                        'event_id' => $eventId,
                    ]);
                }

                $row->fill([
                    'name' => $location->name,
                    'venue_id' => $location->venueId,
                    'position' => $location->position,
                ])->save();
            }
        });
    }
}

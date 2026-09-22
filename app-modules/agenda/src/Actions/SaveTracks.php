<?php

declare(strict_types=1);

namespace Konventix\Agenda\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Agenda\Data\TrackData;
use Konventix\Agenda\Models\Track;

/**
 * Replaces an event's whole track set with the one the editor posted
 * (design §4.1). The payload is the complete desired state, not a diff:
 * a row whose `id` is present is updated, a row without one is created,
 * and a row of this event that the payload no longer names is deleted.
 *
 * Deleting a track leaves its items untracked rather than deleting a
 * programme entry somebody published — that is the `nullOnDelete` on
 * `agenda_items.track_id`, and it is why the editor can reorganize the
 * grid without losing the programme.
 *
 * Two details that are not free:
 *
 * - The deletions happen *before* the writes. `agenda_tracks` is unique on
 *   (event_id, name), so renaming "Bühne" to a name a departing track
 *   still holds would collide if the order were the other way round.
 * - Every read escapes `BrandScope` and filters on the brand by hand. The
 *   editor runs on the operator admin host, where the scope hardens to
 *   zero rows (ADR-0029), so a scoped read would find no existing tracks
 *   and this Action would happily create a second copy of all of them.
 *
 * An `id` that belongs to no track of this event and brand is treated as a
 * new row: it is not ours to update, and refusing the whole save over one
 * stale identifier would lose the rest of the editor's work.
 *
 * @see SaveLocations for the same shape applied to places.
 */
final class SaveTracks
{
    /**
     * @param  list<TrackData>  $tracks
     */
    public function handle(string $eventId, string $brandId, array $tracks): void
    {
        DB::transaction(function () use ($eventId, $brandId, $tracks): void {
            $existing = Track::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('event_id', $eventId)
                ->get()
                ->keyBy('id');

            $kept = array_values(array_filter(array_map(
                static fn (TrackData $track): ?string => $track->id,
                $tracks,
            )));

            foreach ($existing as $track) {
                if (! in_array($track->id, $kept, true)) {
                    $track->delete();
                }
            }

            foreach ($tracks as $track) {
                $row = $track->id === null ? null : $existing->get($track->id);

                if (! $row instanceof Track) {
                    $row = new Track;
                    $row->forceFill([
                        'brand_id' => $brandId,
                        'event_id' => $eventId,
                    ]);
                }

                $row->fill([
                    'name' => $track->name,
                    'position' => $track->position,
                ])->save();
            }
        });
    }
}

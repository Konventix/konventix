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
 * Three details that are not free, all of them about the same index —
 * `agenda_tracks` is unique on (event_id, name), and Postgres checks it
 * immediately, one row at a time:
 *
 * - The deletions happen *before* the writes, so renaming "Bühne" to a name
 *   a departing track still holds does not collide with a row that is on
 *   its way out.
 * - The surviving rows are then renamed *twice*: first to a placeholder, in
 *   the same pass as the deletions, and only afterwards to the name the
 *   payload asked for. Without that first phase, swapping two names in one
 *   submit — "Bühne"→"Foyer" while "Foyer"→"Bühne" — raises
 *   `SQLSTATE[23505]` on the first of the two writes, rolls the transaction
 *   back, and loses every other rename and reorder the operator made in the
 *   same save. Neither name is duplicated *within* the payload, so
 *   `TrackData`'s `distinct` rule cannot see it; this is the same two-phase
 *   shape `Konventix\Event\Actions\SyncEventDays` gives `sequence`.
 *   The placeholder is written through the loaded model on purpose: a bulk
 *   `UPDATE` would leave the in-memory rows holding their old names, the
 *   final `save()` would find nothing dirty, and the placeholders would
 *   stay in the database.
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

                    continue;
                }

                $track->forceFill(['name' => self::placeholderName($track->id)])->save();
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

    /**
     * The name a surviving row wears between the two phases: its own ULID
     * behind a fixed marker, padded past the 80 characters `TrackData`
     * allows a name. Unique per row by construction, so two placeholders
     * cannot collide with each other, and longer than any name the form
     * accepts, so a placeholder cannot collide with a real one either. The
     * column is a plain `string` (255), which leaves room for it.
     */
    private static function placeholderName(string $id): string
    {
        return str_pad("~agenda-renaming~{$id}", 96, '~');
    }
}

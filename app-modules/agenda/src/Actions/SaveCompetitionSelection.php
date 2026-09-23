<?php

declare(strict_types=1);

namespace Konventix\Agenda\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Agenda\Data\CompetitionEntryData;
use Konventix\Agenda\Models\CompetitionEntry;
use Konventix\Agenda\Models\ProgrammeCompetition;
use Konventix\Agenda\Models\Track;

/**
 * Replaces an event's competition selection with the one the programme
 * editor posted (timetable design §4.5, D-AGD-02) — the `SaveTracks`
 * shape: the payload is the complete desired state, an entry the payload
 * no longer names is deleted, and the rest are written in place.
 *
 * **Which competitions may be selected is the boundary that matters.** The
 * ids arrive from the browser, so without a check an editor could put
 * another event's — or another brand's — tournament into this programme by
 * posting its ULID. A competition is allowed iff Agenda's read model holds
 * a row for it on this brand and event, **or** this event already has an
 * entry for it. The second half is not a loophole: an unpublished
 * competition loses its read-model row but keeps the organizer's entry
 * (Task 3, decision 2), and the editor posts the whole set back, withdrawn
 * rows included — refusing them would make every save after an unpublish
 * fail until the organizer removed the row by hand. An entry can only have
 * come into existence through the first half, so the second admits nothing
 * that was not once this event's own.
 *
 * A `trackId` must be one of this event's tracks. The FK alone would accept
 * a track of any event.
 *
 * Refusals are `ValidationException`s keyed `competitions.{index}.…`, the
 * same keys the controller's lifted rules use, so the editor shows each one
 * on its row. The whole set is refused, not the offending entries dropped:
 * a save that silently kept half of what the organizer asked for is worse
 * than one that says which row is wrong.
 *
 * Every read escapes `BrandScope` and names the brand by hand: the editor
 * runs on the operator admin host, where the scope hardens to zero rows
 * (ADR-0029), and a scoped read would find no existing entries and try to
 * insert a second copy of each against the unique (event_id, competition_id).
 */
final class SaveCompetitionSelection
{
    /**
     * @param  list<CompetitionEntryData>  $entries
     */
    public function handle(string $eventId, string $brandId, array $entries): void
    {
        DB::transaction(function () use ($eventId, $brandId, $entries): void {
            $existing = CompetitionEntry::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('event_id', $eventId)
                ->get()
                ->keyBy('competition_id');

            $this->refuseForeignIds($eventId, $brandId, $entries, array_keys($existing->all()));

            $kept = array_map(static fn (CompetitionEntryData $entry): string => $entry->competitionId, $entries);

            foreach ($existing as $competitionId => $entry) {
                if (! in_array($competitionId, $kept, true)) {
                    $entry->delete();
                }
            }

            foreach ($entries as $entry) {
                $row = $existing->get($entry->competitionId);

                if (! $row instanceof CompetitionEntry) {
                    $row = new CompetitionEntry;
                    $row->forceFill([
                        'brand_id' => $brandId,
                        'event_id' => $eventId,
                        'competition_id' => $entry->competitionId,
                    ]);
                }

                $row->fill([
                    'track_id' => $entry->trackId,
                    'position' => $entry->position,
                ])->save();
            }
        });
    }

    /**
     * @param  list<CompetitionEntryData>  $entries
     * @param  array<int|string, int|string>  $alreadySelected
     *
     * @throws ValidationException
     */
    private function refuseForeignIds(string $eventId, string $brandId, array $entries, array $alreadySelected): void
    {
        $competitionIds = array_map(static fn (CompetitionEntryData $entry): string => $entry->competitionId, $entries);
        $trackIds = array_values(array_filter(array_map(static fn (CompetitionEntryData $entry): ?string => $entry->trackId, $entries)));

        $known = $competitionIds === [] ? [] : ProgrammeCompetition::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->whereIn('competition_id', $competitionIds)
            ->pluck('competition_id')
            ->all();

        $allowed = array_map('strval', [...$known, ...$alreadySelected]);

        $tracks = $trackIds === [] ? [] : Track::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->whereIn('id', $trackIds)
            ->pluck('id')
            ->all();

        $errors = [];

        foreach ($entries as $index => $entry) {
            if (! in_array($entry->competitionId, $allowed, true)) {
                $errors["competitions.{$index}.competitionId"] = __('This competition is not one of this event\'s.');
            }

            if ($entry->trackId !== null && ! in_array($entry->trackId, $tracks, true)) {
                $errors["competitions.{$index}.trackId"] = __('This track is not one of this event\'s.');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}

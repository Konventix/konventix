<?php

declare(strict_types=1);

namespace Konventix\Event\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Event\Events\EventDaysChanged;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\EventDay;

/**
 * Replaces an event's day set (decision log Round 6, D-ADM-04). Days are
 * matched by date: an existing row for a kept date survives with its ULID
 * intact (Admission references days by ULID — churning identifiers on
 * every relabel would orphan validity data), removed dates delete, new
 * dates insert. Sequence is re-derived from date order, 1-based. Emits
 * `EventDaysChanged` with the complete resulting set.
 *
 * `sequence` gets a two-phase write (offset, then final): the DB-level
 * unique(event_id, sequence) would otherwise collide mid-reorder.
 *
 * @phpstan-type DayInput array{date: string, label: string|null}
 */
final class SyncEventDays
{
    /**
     * @param  list<array{date: string, label: string|null}>  $days
     */
    public function handle(Event $event, array $days): Event
    {
        return DB::transaction(function () use ($event, $days): Event {
            $byDate = collect($days)
                ->unique(fn (array $day): string => $day['date'])
                ->sortBy('date')
                ->values();

            $keptDates = $byDate->pluck('date')->all();
            $event->days()->whereNotIn('date', $keptDates)->delete();

            // Phase 1: move surviving rows out of the unique(sequence) range.
            $event->days()->update(['sequence' => DB::raw('sequence + 10000')]);

            // Fetched AFTER the offset so the models carry the offset
            // sequence and the final write below is actually dirty. toBase():
            // Eloquent\Collection would re-key only()/except() by primary key.
            $existing = $event->days()->get()->toBase()
                ->keyBy(fn (EventDay $day): string => $day->date->toDateString());

            foreach ($byDate as $index => $day) {
                $sequence = $index + 1;
                $current = $existing->get($day['date']);

                if ($current !== null) {
                    $current->forceFill(['sequence' => $sequence, 'label' => $day['label']])->save();

                    continue;
                }

                $row = new EventDay([
                    'event_id' => $event->id,
                    'date' => $day['date'],
                    'sequence' => $sequence,
                    'label' => $day['label'],
                ]);
                $row->brand_id = $event->brand_id;
                $row->save();
            }

            $event->refresh();

            event(new EventDaysChanged(
                eventId: $event->id,
                brandId: $event->brand_id,
                days: array_values($event->days->map(fn (EventDay $day): array => [
                    'day_id' => $day->id,
                    'date' => $day->date->toDateString(),
                    'sequence' => $day->sequence,
                    'label' => $day->label,
                ])->all()),
            ));

            return $event;
        });
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Agenda\Programme;

use Carbon\CarbonImmutable;
use Konventix\Agenda\Data\ProgrammeCellData;
use Konventix\Agenda\Data\ProgrammeDayData;
use Konventix\Agenda\Data\ProgrammeRowData;
use Konventix\Agenda\Models\AgendaItem;
use Konventix\Agenda\Models\Track;

/**
 * Turns the stored items of one event into the grid the public programme
 * page draws (design §5).
 *
 * **Rows are the distinct times that actually occur** on a day — every
 * `starts_at` and every `ends_at` of that day's visible items, sorted —
 * not fixed half-hour slots (D-AGD-05). That single decision is why a
 * 14:15 workshop needs no special case and why there is no empty 03:00
 * row to scroll past.
 *
 * **Columns are the event's tracks** in `position` order (D-AGD-06). An
 * item occupies the row it begins in and spans the rows it runs through,
 * as `rowspan` on a plain HTML table: the browser does the layout, so
 * there is no overlap arithmetic and no absolute positioning anywhere.
 *
 * **The span is half-open, `[start, end)`.** A row is a *boundary*, not a
 * slot, so the row an item ends on belongs to whatever comes after it:
 * `rowSpan` counts the rows from the item's start up to, but not
 * including, the first row at or after its end. That is the only
 * arithmetic under which a track can hold 14:00–15:00 followed by
 * 15:00–16:00 — rows 14:00, 15:00, 16:00, one row each, 16:00 left as the
 * closing boundary. Counting the end row too would have both of them claim
 * the 15:00 row, and the table would either double up that cell or
 * overflow its `rowspan`. It is also how a printed timetable reads. So an
 * item from 14:00 to 15:00, on a day whose rows are 14:00, 14:15 and
 * 15:00, spans the first two.
 *
 * Two items beginning in the same track at the same time sit side by side
 * in one cell, which is why every cell is a *list*.
 *
 * Three cases the rule has to answer, and its answers:
 *
 * - An item with no end (D-AGD-04's "ab 12:00") occupies exactly one row.
 * - An item with only an end ("bis 08:00") sits in the row of that end.
 * - An item that runs past midnight has no closing boundary on this day,
 *   so it spans through the last row: its end falls on the next day and
 *   starts no row here. `endsAt` still shows the next day's clock time,
 *   because "bis 02:00" is what it means.
 *
 * Items with no track are not a column: they go to `untracked`, the
 * full-width "Ohne Track" band above the grid. A day with no items at all
 * is omitted from the result entirely rather than rendered empty.
 *
 * **Selected competitions are placed like items** (§4.5): the organizer's
 * selection, paired with Agenda's read model of Competition's events, as
 * open-ended blocks at their Competition-owned start (D-AGD-03) in the
 * track the organizer gave the *selection entry*. Both kinds pass through
 * one `ProgrammeEntry`, so every rule above — the rows, the half-open
 * span, the id tie-break — applies to a block exactly as to an item
 * without an end. A competition with no start has no anchor and is left
 * out; a cancelled one stays, struck (D-AGD-08).
 *
 * Everything is resolved **in the event's timezone** (ADR-0027), which the
 * caller passes together with the days: Agenda reads no table of Event's
 * (ADR-0004), so it learns neither the days nor the timezone by itself.
 *
 * Two queries for the items and tracks — the items of the event with their
 * place eager-loaded, and the event's tracks — plus the two of
 * `SelectedCompetitions` and at most one gate lookup, all constant, and
 * all the grouping in PHP. A
 * query per row or per day is what makes a programme page slow. The track
 * is deliberately *not* eager-loaded: the columns come from the tracks
 * query, and an item only ever needs its `track_id` to find its column.
 */
final class ProgrammeBuilder
{
    /**
     * @param  list<array{day_id: string, date: string, label: string|null}>  $days  what the caller holds of `event_days`, in order
     * @param  string|null  $eventSlug  the event's public slug; without it a competition block has no link
     * @return array<int, ProgrammeDayData> one per event day that has items
     */
    public function forEvent(string $eventId, string $brandId, array $days, string $timezone, ?string $eventSlug = null): array
    {
        $items = AgendaItem::query()
            ->visible()
            ->forBrand($brandId)
            ->where('event_id', $eventId)
            ->with(['location'])
            ->orderBy('starts_at')
            ->get();

        $tracks = Track::query()
            ->forBrand($brandId)
            ->where('event_id', $eventId)
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        $entries = [
            ...$items->map(fn (AgendaItem $item): ProgrammeEntry => ProgrammeEntry::ofItem($item))->all(),
            ...SelectedCompetitions::programmeEntries($eventId, $brandId, $eventSlug),
        ];

        /** @var list<string> $trackNames */
        $trackNames = [];

        /** @var array<string, int> $columnOf */
        $columnOf = [];

        foreach ($tracks as $track) {
            $columnOf[$track->id] = count($trackNames);
            $trackNames[] = $track->name;
        }

        $programme = [];

        foreach ($days as $offset => $day) {
            $date = CarbonImmutable::parse($day['date'], $timezone)->toDateString();
            $dayItems = $this->itemsOn($entries, $date, $timezone);

            if ($dayItems === []) {
                continue;
            }

            $programme[] = $this->buildDay($day, $offset, $date, $timezone, $dayItems, $trackNames, $columnOf);
        }

        return $programme;
    }

    /**
     * The items whose anchor — the start, or the end when there is no
     * start — falls on this date in the event's timezone, in the order
     * they happen. The id breaks the tie so that two items beginning
     * together come out the same way on every request.
     *
     * @param  array<int, ProgrammeEntry>  $items
     * @return list<array{item: ProgrammeEntry, anchor: CarbonImmutable}>
     */
    private function itemsOn(array $items, string $date, string $timezone): array
    {
        $found = [];

        foreach ($items as $item) {
            $anchor = $item->startsAt ?? $item->endsAt;

            if ($anchor === null || $anchor->setTimezone($timezone)->toDateString() !== $date) {
                continue;
            }

            $found[] = ['item' => $item, 'anchor' => $anchor];
        }

        usort($found, fn (array $a, array $b): int => [$a['anchor']->getTimestamp(), $a['item']->id]
            <=> [$b['anchor']->getTimestamp(), $b['item']->id]);

        return $found;
    }

    /**
     * @param  array{day_id: string, date: string, label: string|null}  $day
     * @param  list<array{item: ProgrammeEntry, anchor: CarbonImmutable}>  $dayItems
     * @param  array<int, string>  $trackNames
     * @param  array<string, int>  $columnOf
     */
    private function buildDay(
        array $day,
        int $offset,
        string $date,
        string $timezone,
        array $dayItems,
        array $trackNames,
        array $columnOf,
    ): ProgrammeDayData {
        $times = $this->rowTimes($dayItems, $date, $timezone);
        $rowOf = array_flip($times);

        /** @var array<int, array<int, list<ProgrammeCellData>>> $grid */
        $grid = [];

        /** @var list<ProgrammeCellData> $untracked */
        $untracked = [];

        foreach ($dayItems as ['item' => $item, 'anchor' => $anchor]) {
            if ($item->trackId === null || ! isset($columnOf[$item->trackId])) {
                // The band is a list, not a grid. It has no rows to span, so
                // the cell keeps the default span of 1 — computing the grid
                // span here would hand the band a number that means nothing
                // in it, and break what `ProgrammeCellData` promises.
                $untracked[] = $item->cell($timezone, 1);

                continue;
            }

            $startRow = $rowOf[$anchor->setTimezone($timezone)->format('H:i')];
            $closingRow = $this->closingRowOf($item, $anchor, $startRow, $rowOf, count($times), $date, $timezone);

            $grid[$startRow][$columnOf[$item->trackId]][] = $item->cell(
                $timezone,
                max(1, $closingRow - $startRow),
            );
        }

        $rows = [];

        foreach ($times as $row => $time) {
            $cells = [];

            foreach (array_keys($trackNames) as $column) {
                $cells[] = $grid[$row][$column] ?? [];
            }

            $rows[] = new ProgrammeRowData(time: $time, cells: $cells);
        }

        $label = $day['label'] ?? null;

        return new ProgrammeDayData(
            dayId: $day['day_id'],
            // "Tag N" counts the days the caller passed, not the ones that
            // survived: a day without items is omitted, and the day after
            // it must not be renamed because of that.
            label: $label === null || $label === '' ? 'Tag '.($offset + 1) : $label,
            date: $date,
            trackNames: $trackNames,
            rows: $rows,
            untracked: $untracked,
        );
    }

    /**
     * Every distinct wall-clock time that occurs on this day, sorted.
     * Zero-padded `H:i` sorts correctly as a string.
     *
     * An end that falls on another day contributes no row: it is not a
     * time that occurs on *this* day, and a 02:00 end would otherwise sort
     * itself to the top of the grid.
     *
     * @param  list<array{item: ProgrammeEntry, anchor: CarbonImmutable}>  $dayItems
     * @return list<string>
     */
    private function rowTimes(array $dayItems, string $date, string $timezone): array
    {
        $times = [];

        foreach ($dayItems as ['item' => $item]) {
            foreach ([$item->startsAt, $item->endsAt] as $instant) {
                if ($instant === null) {
                    continue;
                }

                $local = $instant->setTimezone($timezone);

                if ($local->toDateString() === $date) {
                    $times[$local->format('H:i')] = true;
                }
            }
        }

        $times = array_keys($times);
        sort($times);

        return $times;
    }

    /**
     * The first row *at or after* the item's end — the exclusive boundary
     * the half-open span stops short of, so that `rowSpan` is
     * `closingRow - startRow`.
     *
     * Every end that falls on this day is itself one of the row times, so
     * "at or after" is just that row. The other cases:
     *
     * - No end, or only an end: the very next row closes it — one row.
     * - Past midnight: there is no closing boundary on this day, so the
     *   boundary is one past the last row and the item spans through it.
     * - An end *before* the item's anchor is data the editor's
     *   `after:startsAt` rule refuses — the check constraint only demands
     *   one of the two times, not their order. Lying on another day, it
     *   closes on the next row; lying on this one, this method returns the
     *   earlier row it genuinely points at, and the `max(1, …)` clamp at
     *   the call site is what keeps the span off zero and below.
     *
     * @param  array<string, int>  $rowOf  wall-clock time to row index
     * @param  int  $rowCount  one past the day's last row
     */
    private function closingRowOf(
        ProgrammeEntry $item,
        CarbonImmutable $anchor,
        int $startRow,
        array $rowOf,
        int $rowCount,
        string $date,
        string $timezone,
    ): int {
        $end = $item->endsAt;

        if ($end === null || $item->startsAt === null) {
            return $startRow + 1;
        }

        $local = $end->setTimezone($timezone);

        if ($local->toDateString() === $date) {
            return $rowOf[$local->format('H:i')] ?? $startRow + 1;
        }

        return $end->greaterThan($anchor) ? $rowCount : $startRow + 1;
    }
}

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
 * The span is inclusive of both boundary rows — an item from 14:00 to
 * 15:00, on a day whose rows are 14:00, 14:15 and 15:00, spans all three,
 * because its own end is one of the times that occur and the block has to
 * reach it. Two items beginning in the same track at the same time sit
 * side by side in one cell, which is why every cell is a *list*.
 *
 * Three cases the rule has to answer, and its answers:
 *
 * - An item with no end (D-AGD-04's "ab 12:00") occupies exactly one row.
 * - An item with only an end ("bis 08:00") sits in the row of that end.
 * - An item that runs past midnight spans to the day's last row. Its end
 *   falls on the next day, so it starts no row here; `endsAt` still shows
 *   the next day's clock time, because "bis 02:00" is what it means.
 *
 * Items with no track are not a column: they go to `untracked`, the
 * full-width "Ohne Track" band above the grid. A day with no items at all
 * is omitted from the result entirely rather than rendered empty.
 *
 * Everything is resolved **in the event's timezone** (ADR-0027), which the
 * caller passes together with the days: Agenda reads no table of Event's
 * (ADR-0004), so it learns neither the days nor the timezone by itself.
 *
 * Two queries, both constant — the items of the event with their track and
 * place eager-loaded, and the event's tracks — and all the grouping in PHP.
 * A query per row or per day is what makes a programme page slow.
 */
final class ProgrammeBuilder
{
    /**
     * @param  list<array{day_id: string, date: string, label: string|null}>  $days  what the caller holds of `event_days`, in order
     * @return array<int, ProgrammeDayData> one per event day that has items
     */
    public function forEvent(string $eventId, string $brandId, array $days, string $timezone): array
    {
        $items = AgendaItem::query()
            ->visible()
            ->forBrand($brandId)
            ->where('event_id', $eventId)
            ->with(['track', 'location'])
            ->orderBy('starts_at')
            ->get();

        $tracks = Track::query()
            ->forBrand($brandId)
            ->where('event_id', $eventId)
            ->orderBy('position')
            ->orderBy('name')
            ->get();

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
            $dayItems = $this->itemsOn($items->all(), $date, $timezone);

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
     * @param  array<int, AgendaItem>  $items
     * @return list<array{item: AgendaItem, anchor: CarbonImmutable}>
     */
    private function itemsOn(array $items, string $date, string $timezone): array
    {
        $found = [];

        foreach ($items as $item) {
            $anchor = $item->starts_at ?? $item->ends_at;

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
     * @param  list<array{item: AgendaItem, anchor: CarbonImmutable}>  $dayItems
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
        $lastRow = count($times) - 1;

        /** @var array<int, array<int, list<ProgrammeCellData>>> $grid */
        $grid = [];

        /** @var list<ProgrammeCellData> $untracked */
        $untracked = [];

        foreach ($dayItems as ['item' => $item, 'anchor' => $anchor]) {
            $startRow = $rowOf[$anchor->setTimezone($timezone)->format('H:i')];
            $endRow = $this->endRowOf($item, $anchor, $startRow, $rowOf, $lastRow, $date, $timezone);

            $cell = ProgrammeCellData::ofItem($item, $timezone, max(1, $endRow - $startRow + 1));

            if ($item->track_id === null || ! isset($columnOf[$item->track_id])) {
                $untracked[] = $cell;

                continue;
            }

            $grid[$startRow][$columnOf[$item->track_id]][] = $cell;
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
     * @param  list<array{item: AgendaItem, anchor: CarbonImmutable}>  $dayItems
     * @return list<string>
     */
    private function rowTimes(array $dayItems, string $date, string $timezone): array
    {
        $times = [];

        foreach ($dayItems as ['item' => $item]) {
            foreach ([$item->starts_at, $item->ends_at] as $instant) {
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
     * The row an item reaches down to. Its own row when it has no end, or
     * only an end; the row of its end when that end is on this day; the
     * day's last row when it runs past midnight. An end that lies *before*
     * the item's anchor is data the editor's `after:startsAt` rule refuses
     * — the check constraint only demands one of the two times, not their
     * order — and is treated as no end rather than as a negative span.
     *
     * @param  array<string, int>  $rowOf  wall-clock time to row index
     */
    private function endRowOf(
        AgendaItem $item,
        CarbonImmutable $anchor,
        int $startRow,
        array $rowOf,
        int $lastRow,
        string $date,
        string $timezone,
    ): int {
        $end = $item->ends_at;

        if ($end === null || $item->starts_at === null) {
            return $startRow;
        }

        $local = $end->setTimezone($timezone);

        if ($local->toDateString() === $date) {
            return $rowOf[$local->format('H:i')] ?? $startRow;
        }

        return $end->greaterThan($anchor) ? $lastRow : $startRow;
    }
}

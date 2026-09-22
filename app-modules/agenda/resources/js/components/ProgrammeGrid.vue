<script setup lang="ts">
import { computed } from 'vue';
import { formatTime } from '@/lib/datetime';
import { timeLabel } from './programmeTime';

/**
 * One day of the programme as a grid (design §5): a plain HTML `<table>`
 * whose rows are the times that actually occur and whose columns are the
 * event's tracks.
 *
 * **The builder has already decided the shape.** `row.cells` holds one list
 * per track, in `day.trackNames` order, and an item appears only in the row
 * it *begins* in — the rows it runs through are covered by its `rowSpan`,
 * which goes straight onto the `<td>` and lets the browser lay the block
 * out. Nothing here re-derives a span from the times; that arithmetic is
 * `ProgrammeBuilder`'s and a second copy of it would drift.
 *
 * What this *does* have to know is which `<td>`s to leave out, and that is
 * not the same question. A `[]` in `row.cells` means "nothing begins here",
 * which covers two different cases: a genuinely empty slot, which needs an
 * empty `<td>`, and a slot a block from an earlier row is already sitting
 * in, which must have no `<td>` at all — emit one there and the browser
 * pushes the rest of the row a column sideways. `covered` answers only that
 * question, and answers it from the `rowSpan`s the builder handed over
 * rather than from any time.
 *
 * `rowSpan` is used **only** on a `<td>` inside this table. The untracked
 * band above is a flat list of things happening that day, not a grid, so it
 * takes no span: the span a cell there carries was computed against a grid
 * it is not in.
 *
 * Two shapes this must survive rather than assume away. A row whose every
 * visible column is empty is legal — a day of purely untracked items still
 * has row times, because those come from every item of the day. And two
 * overlapping items in one track would put a cell in a row another one's
 * span already claims; the cell is still rendered, because losing a
 * published entry is worse than a table that overflows on data nothing
 * forbids yet.
 *
 * The track filter narrows *columns*, not data: `columns` is the indices of
 * the tracks to show, into `day.trackNames` and into every `row.cells`.
 */
type Props = {
    day: Konventix.Agenda.Data.ProgrammeDayData;
    /** Indices into `day.trackNames`, in display order. */
    columns: number[];
};

const props = defineProps<Props>();

/**
 * Per row, the columns a block begun in an earlier row is still occupying.
 * One pass over what the builder sent, no times involved.
 */
const covered = computed<Set<number>[]>(() => {
    const occupied = props.day.rows.map(() => new Set<number>());

    props.day.rows.forEach((row, rowIndex) => {
        row.cells.forEach((cells, column) => {
            cells.forEach((cell) => {
                for (let offset = 1; offset < cell.rowSpan; offset += 1) {
                    occupied[rowIndex + offset]?.add(column);
                }
            });
        });
    });

    return occupied;
});

function cellsAt(
    row: Konventix.Agenda.Data.ProgrammeRowData,
    column: number,
): Konventix.Agenda.Data.ProgrammeCellData[] {
    return row.cells[column] ?? [];
}

function isCovered(rowIndex: number, column: number): boolean {
    return covered.value[rowIndex]?.has(column) ?? false;
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <!-- Design §5: the full-width band above the grid, which is also
             where an all-day item belongs. No rowspan here — this is a
             list, not a grid. -->
        <section
            v-if="props.day.untracked.length > 0"
            class="rounded-lg border border-border bg-surface p-4"
        >
            <h3 class="text-sm font-semibold text-text-muted">Ohne Track</h3>
            <ul class="mt-2 flex flex-col gap-2">
                <li
                    v-for="cell in props.day.untracked"
                    :key="cell.itemId"
                    class="flex flex-wrap items-baseline gap-x-3 gap-y-1"
                >
                    <span class="font-mono text-sm text-text-muted">
                        {{ timeLabel(cell) }}
                    </span>
                    <span
                        class="font-medium text-text"
                        :class="{ 'line-through': cell.cancelled }"
                    >
                        {{ cell.title }}
                    </span>
                    <span v-if="cell.speaker" class="text-sm text-text-muted">
                        {{ cell.speaker }}
                    </span>
                    <span v-if="cell.location" class="text-sm text-text-muted">
                        {{ cell.location }}
                    </span>
                    <span
                        v-if="cell.cancelled"
                        class="w-full text-sm text-danger"
                    >
                        Fällt aus{{
                            cell.cancelledReason
                                ? ` – ${cell.cancelledReason}`
                                : ''
                        }}
                    </span>
                </li>
            </ul>
        </section>

        <div v-if="props.columns.length > 0" class="overflow-x-auto">
            <table class="w-full border-collapse text-left text-sm">
                <caption class="sr-only">
                    Programm für
                    {{
                        props.day.label
                    }}
                </caption>
                <thead>
                    <tr>
                        <th
                            scope="col"
                            class="w-20 border-b border-border p-2 font-semibold text-text-muted"
                        >
                            Zeit
                        </th>
                        <th
                            v-for="column in props.columns"
                            :key="column"
                            scope="col"
                            class="border-b border-border p-2 font-semibold text-text"
                        >
                            {{ props.day.trackNames[column] }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, rowIndex) in props.day.rows"
                        :key="row.time"
                        class="align-top"
                    >
                        <th
                            scope="row"
                            class="border-b border-border p-2 font-mono font-normal text-text-muted"
                        >
                            {{ formatTime(row.time) }}
                        </th>
                        <template v-for="column in props.columns">
                            <td
                                v-for="cell in cellsAt(row, column)"
                                :key="cell.itemId"
                                :rowspan="cell.rowSpan"
                                class="border-b border-l border-border p-2"
                            >
                                <p
                                    class="font-medium text-text"
                                    :class="{ 'line-through': cell.cancelled }"
                                >
                                    {{ cell.title }}
                                </p>
                                <p class="font-mono text-xs text-text-muted">
                                    {{ timeLabel(cell) }}
                                </p>
                                <p
                                    v-if="cell.speaker"
                                    class="text-xs text-text-muted"
                                >
                                    {{ cell.speaker }}
                                </p>
                                <p
                                    v-if="cell.location"
                                    class="text-xs text-text-muted"
                                >
                                    {{ cell.location }}
                                </p>
                                <p
                                    v-if="cell.cancelled"
                                    class="text-xs text-danger"
                                >
                                    Fällt aus{{
                                        cell.cancelledReason
                                            ? ` – ${cell.cancelledReason}`
                                            : ''
                                    }}
                                </p>
                            </td>
                            <td
                                v-if="
                                    cellsAt(row, column).length === 0 &&
                                    !isCovered(rowIndex, column)
                                "
                                :key="`${row.time}-${column}-empty`"
                                class="border-b border-l border-border p-2"
                            ></td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { sortKey, timeLabel } from './programmeTime';

/**
 * The same day, flattened (design §5, "Phone"): the columns collapse into
 * one chronological list and the track becomes a label on each entry. Same
 * data, same page, a CSS breakpoint — never a second request and never a
 * second shape from the server.
 *
 * Every visible cell of the grid appears exactly once, plus the untracked
 * band's entries, which belong in the same chronology here: on a phone
 * there is no band above a grid to put them in. `rowSpan` is ignored
 * outright — a list has no rows to span.
 *
 * The order is the one the grid reads top to bottom: entries come out of
 * `day.rows`, which the builder already sorted, and the untracked ones are
 * merged in by their own start. Sorting is stable in every engine this
 * ships to, so two things at the same time keep the builder's order.
 */
type Entry = {
    key: string;
    time: string;
    track: string | null;
    cell: Konventix.Agenda.Data.ProgrammeCellData;
};

type Props = {
    day: Konventix.Agenda.Data.ProgrammeDayData;
    /** Indices into `day.trackNames`, in display order. */
    columns: number[];
};

const props = defineProps<Props>();

const entries = computed<Entry[]>(() => {
    const flattened: Entry[] = [];

    props.day.rows.forEach((row) => {
        props.columns.forEach((column) => {
            (row.cells[column] ?? []).forEach((cell) => {
                flattened.push({
                    key: cell.itemId,
                    time: row.time,
                    track: props.day.trackNames[column] ?? null,
                    cell,
                });
            });
        });
    });

    props.day.untracked.forEach((cell) => {
        flattened.push({
            key: cell.itemId,
            time: sortKey(cell),
            track: null,
            cell,
        });
    });

    return flattened.sort((a, b) => a.time.localeCompare(b.time));
});
</script>

<template>
    <!--
    datetime-raw: cell.startsAt
    datetime-raw: cell.endsAt
    datetime-raw: entry.time
    A programme time is the organizer's wall clock in the event's own zone
    (ADR-0027), already resolved by ProgrammeBuilder — printed exactly as
    promised and never converted to the reader's. See components/programmeTime.ts.
-->
    <ol class="flex flex-col gap-3">
        <li
            v-for="entry in entries"
            :key="entry.key"
            class="rounded-lg border border-border bg-surface p-3"
        >
            <p class="font-mono text-xs text-text-muted">
                {{ timeLabel(entry.cell) }}
            </p>
            <p
                class="font-medium text-text"
                :class="{ 'line-through': entry.cell.cancelled }"
            >
                {{ entry.cell.title }}
            </p>
            <p
                v-if="entry.track || entry.cell.location || entry.cell.speaker"
                class="text-sm text-text-muted"
            >
                <span v-if="entry.track">{{ entry.track }}</span>
                <span v-if="entry.track && entry.cell.location"> · </span>
                <span v-if="entry.cell.location">{{
                    entry.cell.location
                }}</span>
                <span
                    v-if="
                        (entry.track || entry.cell.location) &&
                        entry.cell.speaker
                    "
                >
                    ·
                </span>
                <span v-if="entry.cell.speaker">{{ entry.cell.speaker }}</span>
            </p>
            <p v-if="entry.cell.cancelled" class="text-sm text-danger">
                Fällt aus{{
                    entry.cell.cancelledReason
                        ? ` – ${entry.cell.cancelledReason}`
                        : ''
                }}
            </p>
        </li>

        <li v-if="entries.length === 0" class="text-sm text-text-muted">
            Für diesen Tag ist noch nichts angekündigt.
        </li>
    </ol>
</template>

<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { formatDateTime } from '@/lib/datetime';
import { update as updateCompetitions } from '@/routes/admin/events/agenda/competitions';

/**
 * The programme editor's second half (timetable design §4.5, §6,
 * D-AGD-02): which of the event's published competitions the programme
 * shows, under which track and in what order. Console copy, so English.
 *
 * **Its own form and its own endpoint.** Tracks and places are one whole
 * set on the page's form; this is another whole set beside it, saved by
 * `SaveCompetitionSelection`. Keeping them apart means a refused
 * competition never costs the operator a track rename, and the page itself
 * stays free of new logic (it is long enough already).
 *
 * **The start time is Competition's** (D-AGD-03) and shown read-only; there
 * is nothing here to move a tournament with. A competition without one is
 * selectable but not shown in the programme until Competition gives it a
 * time, and the row says so.
 *
 * **A withdrawn row** is a selection whose competition was unpublished in
 * Competition. Agenda no longer knows its name, the programme no longer
 * shows it, and the entry is kept so that republishing restores the block
 * where the organizer put it. It is posted back with every save until the
 * organizer removes it here.
 *
 * **Order is buttons, not dragging**, as on the rest of the page. The list
 * order *is* the programme order: the selected rows are numbered from zero
 * in the order they stand here when the form is posted.
 */
type Row = {
    competitionId: string;
    name: string;
    startsAt: string | null;
    cancelled: boolean;
    withdrawn: boolean;
    selected: boolean;
    trackId: string | null;
};

const props = defineProps<{
    eventId: string;
    competitions: Konventix.Agenda.Data.CompetitionSelectionRowData[];
    tracks: Konventix.Agenda.Data.TrackData[];
}>();

function seed(): Row[] {
    return props.competitions.map((row): Row => ({
        competitionId: row.competitionId,
        name: row.name,
        startsAt: row.startsAt,
        cancelled: row.cancelled,
        withdrawn: row.withdrawn,
        selected: row.selected,
        trackId: row.trackId,
    }));
}

const form = useForm({ rows: seed() });

// The wire shape is a list of `CompetitionEntryData`: the selected rows
// only, numbered in the order they stand.
form.transform(
    (data): { competitions: Konventix.Agenda.Data.CompetitionEntryData[] } => ({
        competitions: data.rows
            .filter((row) => row.selected)
            .map((row, position) => ({
                competitionId: row.competitionId,
                trackId: row.trackId,
                position,
            })),
    }),
);

useUnsavedChanges(() => form.isDirty);

/**
 * The server keys a refusal by the row's index in the *posted* list —
 * `competitions.{index}.competitionId` — and only selected rows are
 * posted, so the index is counted among those.
 */
const postedIndex = computed<Record<string, number>>(() => {
    const indexes: Record<string, number> = {};
    let next = 0;

    form.rows.forEach((row) => {
        if (row.selected) {
            indexes[row.competitionId] = next;
            next += 1;
        }
    });

    return indexes;
});

function errorFor(
    row: Row,
    field: 'competitionId' | 'trackId',
): string | undefined {
    const index = postedIndex.value[row.competitionId];

    if (index === undefined) {
        return undefined;
    }

    return (form.errors as Record<string, string | undefined>)[
        `competitions.${index}.${field}`
    ];
}

function move(index: number, by: number): void {
    const target = index + by;
    const moved = form.rows[index];
    const displaced = form.rows[target];

    if (moved === undefined || displaced === undefined) {
        return;
    }

    form.rows[index] = displaced;
    form.rows[target] = moved;
}

function remove(index: number): void {
    form.rows.splice(index, 1);
}

function submit(): void {
    form.put(updateCompetitions(props.eventId).url, {
        preserveScroll: true,
        // Re-seed from what the server now holds, so a removed withdrawn row
        // is gone and a competition announced meanwhile appears.
        onSuccess: () => {
            form.defaults({ rows: seed() });
            form.reset();
        },
    });
}

const savedTracks = computed(() =>
    props.tracks.filter(
        (track): track is Konventix.Agenda.Data.TrackData & { id: string } =>
            track.id !== null,
    ),
);
</script>

<template>
    <Card class="border-border bg-surface">
        <CardHeader><CardTitle>Competitions</CardTitle></CardHeader>
        <CardContent>
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <p
                    v-if="form.rows.length === 0"
                    class="text-sm text-text-muted"
                >
                    This event has no published competitions yet. Once
                    Competition publishes one, it can be added to the programme
                    here.
                </p>

                <ul v-else class="flex flex-col gap-3">
                    <li
                        v-for="(row, index) in form.rows"
                        :key="row.competitionId"
                        class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border p-3"
                    >
                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            <div class="flex items-center gap-2">
                                <Checkbox
                                    v-if="!row.withdrawn"
                                    :id="`competition-${row.competitionId}`"
                                    :model-value="row.selected"
                                    :aria-describedby="`competition-${row.competitionId}-note`"
                                    @update:model-value="
                                        row.selected = $event === true
                                    "
                                />
                                <Label
                                    :for="
                                        row.withdrawn
                                            ? undefined
                                            : `competition-${row.competitionId}`
                                    "
                                    class="font-medium text-text"
                                    :class="{
                                        'line-through': row.cancelled,
                                        'text-text-muted': row.withdrawn,
                                    }"
                                >
                                    {{ row.name }}
                                </Label>
                            </div>
                            <p
                                :id="`competition-${row.competitionId}-note`"
                                class="text-sm text-text-muted"
                            >
                                <span v-if="row.withdrawn" class="text-warning">
                                    Unpublished in Competition — not shown
                                </span>
                                <template v-else>
                                    <span v-if="row.startsAt">{{
                                        formatDateTime(row.startsAt)
                                    }}</span>
                                    <span v-else class="text-warning">
                                        No start time yet — not shown in the
                                        programme
                                    </span>
                                    <span
                                        v-if="row.cancelled"
                                        class="text-danger"
                                    >
                                        · Cancelled — shown struck through
                                    </span>
                                </template>
                            </p>
                            <InputError
                                :message="errorFor(row, 'competitionId')"
                            />
                        </div>

                        <div class="flex items-end gap-2">
                            <div class="grid gap-1">
                                <Label
                                    :for="`competition-${row.competitionId}-track`"
                                    class="text-xs text-text-muted"
                                >
                                    Track
                                </Label>
                                <select
                                    :id="`competition-${row.competitionId}-track`"
                                    :value="row.trackId ?? ''"
                                    :disabled="!row.selected"
                                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm disabled:opacity-50"
                                    @change="
                                        row.trackId =
                                            ($event.target as HTMLSelectElement)
                                                .value || null
                                    "
                                >
                                    <option value="">No track</option>
                                    <option
                                        v-for="track in savedTracks"
                                        :key="track.id"
                                        :value="track.id"
                                    >
                                        {{ track.name }}
                                    </option>
                                </select>
                                <InputError
                                    :message="errorFor(row, 'trackId')"
                                />
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                :aria-label="`Move ${row.name} up`"
                                :disabled="index === 0"
                                @click="move(index, -1)"
                            >
                                <ArrowUp class="size-4" />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                :aria-label="`Move ${row.name} down`"
                                :disabled="index === form.rows.length - 1"
                                @click="move(index, 1)"
                            >
                                <ArrowDown class="size-4" />
                            </Button>
                            <Button
                                v-if="row.withdrawn"
                                type="button"
                                variant="ghost"
                                size="sm"
                                aria-label="Remove withdrawn competition from the programme"
                                @click="remove(index)"
                            >
                                <Trash2 class="size-4 text-danger" />
                            </Button>
                        </div>
                    </li>
                </ul>

                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing">
                        Save competitions
                    </Button>
                    <span
                        v-if="form.isDirty"
                        role="status"
                        class="text-sm text-warning"
                    >
                        Unsaved changes
                    </span>
                </div>

                <p class="text-xs text-text-muted">
                    Start times belong to Competition and move here by
                    themselves. A selected competition appears in the programme
                    as an open-ended block at its start time.
                </p>
            </form>
        </CardContent>
    </Card>
</template>

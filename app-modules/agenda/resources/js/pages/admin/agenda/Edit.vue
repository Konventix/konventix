<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import DateTimeField from '@/components/datetime/DateTimeField.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import {
    formatDateTime,
    toInstant,
    toLocalDateTimeInput,
} from '@/lib/datetime';
import { show as showEvent } from '@/routes/admin/events';
import { update as updateAgenda } from '@/routes/admin/events/agenda';
import {
    cancel as cancelItemRoute,
    destroy as destroyItemRoute,
    publish as publishItemRoute,
    store as storeItemRoute,
    update as updateItemRoute,
} from '@/routes/admin/events/agenda/items';
import CompetitionSelection from '../../../components/admin/CompetitionSelection.vue';

/**
 * The programme editor (design §6): tracks, places and items for one
 * event, under `ManageAgenda`. Console copy, so English.
 *
 * **Two different kinds of write, so two different forms.** Tracks and
 * places are saved as whole sets — `SaveTracks`/`SaveLocations` take the
 * complete desired state and derive creates, renames and deletions from it,
 * which is what lets renaming a track rename it on every item at once. An
 * item is saved one at a time, because publishing one and calling one off
 * are separate acts with separate announcements behind them (design §7),
 * and a `state` field on a form would emit none of them.
 *
 * **Order is buttons, not dragging.** No drag library is installed and none
 * may be added; up/down renumber `position`, which is the only thing the
 * grid reads.
 *
 * **The two times may each be left empty.** D-AGD-04 allows three shapes —
 * both ends, a start only ("Einlass ab 12:00"), an end only ("bis 08:00") —
 * and only "neither" is refused, by the check constraint and by
 * `AgendaItemData`'s rules. `DateTimeField` speaks the operator's own wall
 * clock and `toInstant()` resolves it against their zone before it is
 * posted (`.ai/rules/js.md`); the *event's* zone is where those instants
 * are read back out on the public page, which is why the operator's clock
 * here and the participant's clock there are not the same number.
 */
type ItemRow = Konventix.Agenda.Data.AgendaItemData & {
    state: Konventix.Agenda.Enums.ItemState;
    cancelledReason: string | null;
};

type TrackRow = { id: string | null; name: string; position: number };
type LocationRow = {
    id: string | null;
    name: string;
    venueId: string | null;
    position: number;
};

type Props = {
    event: {
        id: string;
        name: string | null;
        slug: string | null;
        timezone: string;
    };
    tracks: Konventix.Agenda.Data.TrackData[];
    locations: Konventix.Agenda.Data.LocationData[];
    items: ItemRow[];
    days: { day_id: string; date: string; label: string | null }[];
    competitions: Konventix.Agenda.Data.CompetitionSelectionRowData[];
};

const props = defineProps<Props>();

const form = useForm({
    tracks: props.tracks.map((track): TrackRow => ({ ...track })),
    locations: props.locations.map((location): LocationRow => ({
        ...location,
    })),
});

function submit(): void {
    form.put(updateAgenda(props.event.id).url, { preserveScroll: true });
}

function addTrack(): void {
    form.tracks.push({
        id: null,
        name: '',
        position: form.tracks.length,
    });
}

function addLocation(): void {
    form.locations.push({
        id: null,
        name: '',
        venueId: null,
        position: form.locations.length,
    });
}

/**
 * Swap with the neighbour and renumber every row from zero, rather than
 * nudging one `position` — the numbers are an order, not an identity, and a
 * set that has been added to and deleted from has gaps in it.
 */
function move<T extends { position: number }>(
    rows: T[],
    index: number,
    by: number,
): void {
    const target = index + by;

    if (target < 0 || target >= rows.length) {
        return;
    }

    const moved = rows[index];
    const displaced = rows[target];

    if (moved === undefined || displaced === undefined) {
        return;
    }

    rows[index] = displaced;
    rows[target] = moved;
    rows.forEach((row, position) => {
        row.position = position;
    });
}

/**
 * Deleting a track leaves its items untracked and deleting a place leaves
 * them placeless — the Actions' `nullOnDelete`, not a cascade — so this
 * asks first rather than quietly reorganising somebody's published grid.
 */
function removeTrack(index: number): void {
    const track = form.tracks[index];

    if (
        track?.id !== null &&
        !window.confirm(
            'Remove this track? Items on it stay, but lose their column.',
        )
    ) {
        return;
    }

    form.tracks.splice(index, 1);
    form.tracks.forEach((row, position) => {
        row.position = position;
    });
}

function removeLocation(index: number): void {
    const location = form.locations[index];

    if (
        location?.id !== null &&
        !window.confirm('Remove this place? Items there keep their note only.')
    ) {
        return;
    }

    form.locations.splice(index, 1);
    form.locations.forEach((row, position) => {
        row.position = position;
    });
}

/** One form for "add" and for "edit"; `editingId` says which. */
const editingId = ref<string | null>(null);

const itemForm = useForm({
    title: '',
    description: null as string | null,
    trackId: null as string | null,
    locationId: null as string | null,
    locationNote: null as string | null,
    speaker: null as string | null,
    startsAt: '',
    endsAt: '',
});

// The wire shape is `AgendaItemData`: the two wall clocks the operator
// typed become instants here, in the browser, which is the only place that
// knows which zone they were typed in (`.ai/rules/js.md`). An empty field
// resolves to null, which is how "ab 12:00" and "bis 08:00" are posted.
itemForm.transform((data) => ({
    id: editingId.value,
    title: data.title,
    description: data.description,
    trackId: data.trackId,
    locationId: data.locationId,
    locationNote: data.locationNote,
    speaker: data.speaker,
    startsAt: toInstant(data.startsAt),
    endsAt: toInstant(data.endsAt),
}));

function resetItemForm(): void {
    editingId.value = null;
    itemForm.reset();
    itemForm.clearErrors();
}

function editItem(item: ItemRow): void {
    editingId.value = item.id;
    itemForm.clearErrors();
    itemForm.title = item.title;
    itemForm.description = item.description;
    itemForm.trackId = item.trackId;
    itemForm.locationId = item.locationId;
    itemForm.locationNote = item.locationNote;
    itemForm.speaker = item.speaker;
    itemForm.startsAt = toLocalDateTimeInput(item.startsAt);
    itemForm.endsAt = toLocalDateTimeInput(item.endsAt);
}

function submitItem(): void {
    const id = editingId.value;

    if (id === null) {
        itemForm.post(storeItemRoute(props.event.id).url, {
            preserveScroll: true,
            onSuccess: resetItemForm,
        });

        return;
    }

    itemForm.put(updateItemRoute([props.event.id, id]).url, {
        preserveScroll: true,
        onSuccess: resetItemForm,
    });
}

function publishItem(item: ItemRow): void {
    if (item.id === null) {
        return;
    }

    router.post(
        publishItemRoute([props.event.id, item.id]).url,
        {},
        { preserveScroll: true },
    );
}

/**
 * D-AGD-08: the item stays on the public page, struck through, carrying
 * this reason — so the reason is asked for rather than assumed. An empty
 * answer still cancels; only dismissing the prompt calls it off.
 */
function cancelItem(item: ItemRow): void {
    if (item.id === null) {
        return;
    }

    const reason = window.prompt(
        'Why is this cancelled? Participants read this on the programme.',
        item.cancelledReason ?? '',
    );

    if (reason === null) {
        return;
    }

    router.post(
        cancelItemRoute([props.event.id, item.id]).url,
        { reason: reason === '' ? null : reason },
        { preserveScroll: true },
    );
}

function destroyItem(item: ItemRow): void {
    if (
        item.id === null ||
        !window.confirm(
            'Delete this item? Cancel it instead if anyone has seen it.',
        )
    ) {
        return;
    }

    router.delete(destroyItemRoute([props.event.id, item.id]).url, {
        preserveScroll: true,
    });
}

const trackNames = computed<Record<string, string>>(() =>
    Object.fromEntries(
        props.tracks
            .filter((track) => track.id !== null)
            .map((track) => [track.id as string, track.name]),
    ),
);

const locationNames = computed<Record<string, string>>(() =>
    Object.fromEntries(
        props.locations
            .filter((location) => location.id !== null)
            .map((location) => [location.id as string, location.name]),
    ),
);

// ADR-0038: both forms on this page are stateful, and the guard is asked
// about either. Declared after `itemForm` so the closure names nothing that
// does not exist yet.
useUnsavedChanges(() => form.isDirty || itemForm.isDirty);

const stateBadgeClass: Record<string, string> = {
    draft: 'bg-surface-muted text-text-muted',
    published: 'bg-success/15 text-success',
    cancelled: 'bg-danger/15 text-danger',
};
</script>

<template>
    <Head :title="`Programme — ${props.event.name ?? ''}`" />

    <div class="flex max-w-4xl flex-col gap-6">
        <AdminPageHeader
            title="Programme"
            :description="`Tracks, places and items — item times are stored as instants and shown in the event's zone (${props.event.timezone}) on the public page`"
        >
            <Link
                :href="showEvent(props.event.id).url"
                class="text-sm text-text-muted hover:text-text"
            >
                Dashboard
            </Link>
        </AdminPageHeader>

        <!-- The event's days, as Agenda was told them (ADR-0004). An item
             outside all of them is still saved and still published — it
             simply has no day to appear under, which is worth seeing here
             rather than discovering on the public page. -->
        <p v-if="props.days.length > 0" class="text-sm text-text-muted">
            Event days:
            <span v-for="(day, index) in props.days" :key="day.day_id">
                <span v-if="index > 0">, </span>{{ day.date
                }}<span v-if="day.label"> ({{ day.label }})</span>
            </span>
        </p>
        <p v-else class="text-sm text-warning" role="status">
            This event has no days yet — the public programme has nothing to
            group items under until it does.
        </p>

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <Card class="border-border bg-surface">
                <CardHeader><CardTitle>Tracks</CardTitle></CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <p
                        v-if="form.tracks.length === 0"
                        class="text-sm text-text-muted"
                    >
                        No tracks yet. Without one, every item lands in the
                        “Ohne Track” band above the grid.
                    </p>
                    <div
                        v-for="(track, index) in form.tracks"
                        :key="track.id ?? `new-track-${index}`"
                        class="flex items-end gap-2"
                    >
                        <div class="grid flex-1 gap-2">
                            <Label :for="`track-${index}-name`">Name</Label>
                            <Input
                                :id="`track-${index}-name`"
                                v-model="track.name"
                            />
                            <InputError
                                :message="form.errors[`tracks.${index}.name`]"
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-label="Move track up"
                            :disabled="index === 0"
                            @click="move(form.tracks, index, -1)"
                        >
                            <ArrowUp class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-label="Move track down"
                            :disabled="index === form.tracks.length - 1"
                            @click="move(form.tracks, index, 1)"
                        >
                            <ArrowDown class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-label="Remove track"
                            @click="removeTrack(index)"
                        >
                            <Trash2 class="size-4 text-danger" />
                        </Button>
                    </div>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        class="self-start"
                        @click="addTrack"
                    >
                        <Plus class="size-4" />
                        Add track
                    </Button>
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader><CardTitle>Places</CardTitle></CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <p
                        v-if="form.locations.length === 0"
                        class="text-sm text-text-muted"
                    >
                        No places yet. An item can still carry a free-text note
                        instead.
                    </p>
                    <div
                        v-for="(location, index) in form.locations"
                        :key="location.id ?? `new-location-${index}`"
                        class="flex items-end gap-2"
                    >
                        <div class="grid flex-1 gap-2">
                            <Label :for="`location-${index}-name`">Name</Label>
                            <Input
                                :id="`location-${index}-name`"
                                v-model="location.name"
                            />
                            <InputError
                                :message="
                                    form.errors[`locations.${index}.name`]
                                "
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-label="Move place up"
                            :disabled="index === 0"
                            @click="move(form.locations, index, -1)"
                        >
                            <ArrowUp class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-label="Move place down"
                            :disabled="index === form.locations.length - 1"
                            @click="move(form.locations, index, 1)"
                        >
                            <ArrowDown class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-label="Remove place"
                            @click="removeLocation(index)"
                        >
                            <Trash2 class="size-4 text-danger" />
                        </Button>
                    </div>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        class="self-start"
                        @click="addLocation"
                    >
                        <Plus class="size-4" />
                        Add place
                    </Button>
                </CardContent>
            </Card>

            <div class="flex items-center gap-3 self-start">
                <Button type="submit" :disabled="form.processing">
                    Save tracks and places
                </Button>
                <span
                    v-if="form.isDirty"
                    role="status"
                    class="text-sm text-warning"
                >
                    Unsaved changes
                </span>
            </div>
        </form>

        <!-- The competition half (design §4.5, D-AGD-02): its own form. -->
        <CompetitionSelection
            :event-id="props.event.id"
            :competitions="props.competitions"
            :tracks="props.tracks"
        />

        <Card class="border-border bg-surface">
            <CardHeader><CardTitle>Items</CardTitle></CardHeader>
            <CardContent class="flex flex-col gap-6">
                <p
                    v-if="props.items.length === 0"
                    class="text-sm text-text-muted"
                >
                    Nothing on the programme yet.
                </p>

                <ul v-else class="flex flex-col gap-3">
                    <li
                        v-for="item in props.items"
                        :key="item.id ?? item.title"
                        class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border p-3"
                    >
                        <div class="flex min-w-0 flex-col gap-1">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-text">
                                    {{ item.title }}
                                </span>
                                <span
                                    class="rounded px-1.5 py-0.5 text-xs"
                                    :class="
                                        stateBadgeClass[item.state] ??
                                        'bg-surface-muted text-text-muted'
                                    "
                                >
                                    {{ item.state }}
                                </span>
                            </div>
                            <p class="text-sm text-text-muted">
                                <span v-if="item.startsAt">{{
                                    formatDateTime(item.startsAt)
                                }}</span>
                                <span v-if="item.endsAt">
                                    – {{ formatDateTime(item.endsAt) }}</span
                                >
                                <span v-if="item.trackId">
                                    · {{ trackNames[item.trackId] }}</span
                                >
                                <span v-if="item.locationId">
                                    · {{ locationNames[item.locationId] }}</span
                                >
                                <span v-else-if="item.locationNote">
                                    · {{ item.locationNote }}</span
                                >
                            </p>
                            <p
                                v-if="item.cancelledReason"
                                class="text-sm text-danger"
                            >
                                {{ item.cancelledReason }}
                            </p>
                        </div>

                        <div class="flex items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="editItem(item)"
                            >
                                Edit
                            </Button>
                            <Button
                                v-if="item.state !== 'published'"
                                type="button"
                                variant="secondary"
                                size="sm"
                                @click="publishItem(item)"
                            >
                                Publish
                            </Button>
                            <Button
                                v-if="item.state !== 'cancelled'"
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="cancelItem(item)"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                aria-label="Delete item"
                                @click="destroyItem(item)"
                            >
                                <Trash2 class="size-4 text-danger" />
                            </Button>
                        </div>
                    </li>
                </ul>

                <form
                    class="flex flex-col gap-4 border-t border-border pt-4"
                    @submit.prevent="submitItem"
                >
                    <h3 class="text-sm font-semibold text-text">
                        {{ editingId === null ? 'New item' : 'Edit item' }}
                    </h3>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="item-title">Title</Label>
                            <Input id="item-title" v-model="itemForm.title" />
                            <InputError :message="itemForm.errors.title" />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="item-description">Description</Label>
                            <Input
                                id="item-description"
                                :model-value="itemForm.description ?? ''"
                                @update:model-value="
                                    itemForm.description =
                                        String($event) || null
                                "
                            />
                            <InputError
                                :message="itemForm.errors.description"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="item-speaker">Speaker</Label>
                            <Input
                                id="item-speaker"
                                :model-value="itemForm.speaker ?? ''"
                                @update:model-value="
                                    itemForm.speaker = String($event) || null
                                "
                            />
                            <InputError :message="itemForm.errors.speaker" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="item-track">Track</Label>
                            <select
                                id="item-track"
                                :value="itemForm.trackId ?? ''"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                                @change="
                                    itemForm.trackId =
                                        ($event.target as HTMLSelectElement)
                                            .value || null
                                "
                            >
                                <option value="">No track</option>
                                <option
                                    v-for="track in props.tracks"
                                    :key="track.id ?? track.name"
                                    :value="track.id ?? ''"
                                >
                                    {{ track.name }}
                                </option>
                            </select>
                            <InputError :message="itemForm.errors.trackId" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="item-location">Place</Label>
                            <select
                                id="item-location"
                                :value="itemForm.locationId ?? ''"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                                @change="
                                    itemForm.locationId =
                                        ($event.target as HTMLSelectElement)
                                            .value || null
                                "
                            >
                                <option value="">No place</option>
                                <option
                                    v-for="location in props.locations"
                                    :key="location.id ?? location.name"
                                    :value="location.id ?? ''"
                                >
                                    {{ location.name }}
                                </option>
                            </select>
                            <InputError :message="itemForm.errors.locationId" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="item-location-note">
                                Place note (free text)
                            </Label>
                            <Input
                                id="item-location-note"
                                :model-value="itemForm.locationNote ?? ''"
                                @update:model-value="
                                    itemForm.locationNote =
                                        String($event) || null
                                "
                            />
                            <InputError
                                :message="itemForm.errors.locationNote"
                            />
                        </div>
                        <DateTimeField
                            id="item-starts-at"
                            v-model="itemForm.startsAt"
                            label="Starts"
                            hint="Leave empty for an item that only says when it ends."
                            :error="itemForm.errors.startsAt"
                        />
                        <DateTimeField
                            id="item-ends-at"
                            v-model="itemForm.endsAt"
                            label="Ends"
                            hint="Leave empty for an open-ended item (“ab 12:00”)."
                            :error="itemForm.errors.endsAt"
                        />
                    </div>

                    <div class="flex items-center gap-3">
                        <Button type="submit" :disabled="itemForm.processing">
                            {{ editingId === null ? 'Add item' : 'Save item' }}
                        </Button>
                        <Button
                            v-if="editingId !== null"
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="resetItemForm"
                        >
                            Cancel editing
                        </Button>
                        <span
                            v-if="itemForm.isDirty"
                            role="status"
                            class="text-sm text-warning"
                        >
                            Unsaved changes
                        </span>
                    </div>

                    <p class="text-xs text-text-muted">
                        A new item starts as a draft — the public programme
                        shows it only once it is published. Cancelling keeps it
                        there, struck through, with its reason.
                    </p>
                </form>
            </CardContent>
        </Card>
    </div>
</template>

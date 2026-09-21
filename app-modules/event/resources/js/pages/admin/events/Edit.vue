<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Plus, Star, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import MediaPicker from '@/components/admin/MediaPicker.vue';
import DateField from '@/components/datetime/DateField.vue';
import TimeField from '@/components/datetime/TimeField.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAdminCapabilities } from '@/composables/useAdminCapabilities';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import {
    show as showEvent,
    update as updateEvent,
} from '@/routes/admin/events';
import {
    destroy as unassignVenue,
    store as assignVenue,
} from '@/routes/admin/events/venues';

/**
 * Event editing: details (EventData shape), the day set (D-ADM-04), and
 * venue assignment (D-EVT-15). Times are wall-clock strings + zone
 * (ADR-0027) — never a `datetime-local` control, whose value the browser
 * would resolve against the *reader's* zone and turn into an instant.
 *
 * `DateField` and `TimeField` (#242) are exactly the right shape for one:
 * neither converts anything, because a date and a time on their own have no
 * offset to convert against. They replace three free-text inputs that asked
 * the operator to type `Y-m-d H:i:s` by hand and had no labels on the day
 * rows at all.
 */
type Props = {
    event: {
        id: string;
        state: string;
        name: string;
        slug: string;
        description: string | null;
        startsAt: string;
        endsAt: string;
        timezone: string;
        visibility: string;
        reentryPolicy: string;
        /** ADR-0064: the per-event attendee-list switch. */
        attendeeListVisibility: string;
        predecessorEventId: string | null;
        bannerMediaId: string | null;
        brandId: string;
    };
    days: { date: string; label: string | null }[];
    venues: { id: string; name: string; city: string; isPrimary: boolean }[];
    availableVenues: { id: string; name: string; city: string }[];
    /** The brand's images for the banner picker (#292). */
    media: Konventix.Platform.Data.MediaItemData[];
};

const props = defineProps<Props>();
const { canManageMedia } = useAdminCapabilities();

const form = useForm({
    name: props.event.name,
    slug: props.event.slug,
    description: props.event.description,
    startsAt: props.event.startsAt,
    endsAt: props.event.endsAt,
    timezone: props.event.timezone,
    visibility: props.event.visibility,
    reentryPolicy: props.event.reentryPolicy,
    attendeeListVisibility: props.event.attendeeListVisibility,
    predecessorEventId: props.event.predecessorEventId,
    bannerMediaId: props.event.bannerMediaId,
    days: [...props.days],
});

/**
 * The two halves of an ADR-0027 wall clock, `Y-m-d H:i:s`.
 *
 * Joined back exactly as it came apart, seconds included: `UpdateEventDetails`
 * decides whether an event was *rescheduled* by comparing the stored
 * `Y-m-d H:i:s` against what arrives, so normalising the seconds away would
 * announce a reschedule to everyone holding a registration every time somebody
 * saved the description.
 */
function wallClockParts(value: string): [string, string, string] {
    const parts = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(?::(\d{2}))?$/.exec(
        value,
    );

    return parts === null
        ? ['', '', '00']
        : [parts[1], parts[2], parts[3] ?? '00'];
}

function joinWallClock(value: string, date: string, time: string): string {
    const [, , seconds] = wallClockParts(value);

    return date === ''
        ? ''
        : `${date} ${time === '' ? '00:00' : time}:${seconds}`;
}

const startsOn = computed({
    get: () => wallClockParts(form.startsAt)[0],
    set: (date: string) =>
        (form.startsAt = joinWallClock(
            form.startsAt,
            date,
            wallClockParts(form.startsAt)[1],
        )),
});

const startsAtTime = computed({
    get: () => wallClockParts(form.startsAt)[1],
    set: (time: string) =>
        (form.startsAt = joinWallClock(
            form.startsAt,
            wallClockParts(form.startsAt)[0],
            time,
        )),
});

const endsOn = computed({
    get: () => wallClockParts(form.endsAt)[0],
    set: (date: string) =>
        (form.endsAt = joinWallClock(
            form.endsAt,
            date,
            wallClockParts(form.endsAt)[1],
        )),
});

const endsAtTime = computed({
    get: () => wallClockParts(form.endsAt)[1],
    set: (time: string) =>
        (form.endsAt = joinWallClock(
            form.endsAt,
            wallClockParts(form.endsAt)[0],
            time,
        )),
});

useUnsavedChanges(() => form.isDirty);

function submit(): void {
    form.put(updateEvent(props.event.id).url, { preserveScroll: true });
}

function addDay(): void {
    form.days.push({ date: '', label: null });
}

function removeDay(index: number): void {
    form.days.splice(index, 1);
}

const venueToAssign = ref(props.availableVenues[0]?.id ?? '');
const assignAsPrimary = ref(false);

function submitVenue(): void {
    router.post(
        assignVenue(props.event.id).url,
        { venue_id: venueToAssign.value, is_primary: assignAsPrimary.value },
        { preserveScroll: true },
    );
}

function removeVenue(venueId: string): void {
    router.delete(unassignVenue([props.event.id, venueId]).url, {
        preserveScroll: true,
    });
}

function promoteVenue(venueId: string): void {
    router.post(
        assignVenue(props.event.id).url,
        { venue_id: venueId, is_primary: true },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="`Edit — ${props.event.name}`" />

    <div class="flex max-w-3xl flex-col gap-6">
        <AdminPageHeader
            :title="props.event.name"
            description="Details, days and venues — times are wall-clock in the event's zone"
        >
            <Link
                :href="showEvent(props.event.id).url"
                class="text-sm text-text-muted hover:text-text"
            >
                Dashboard
            </Link>
        </AdminPageHeader>

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <Card class="border-border bg-surface">
                <CardHeader><CardTitle>Details</CardTitle></CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="edit-name">Name</Label>
                            <Input id="edit-name" v-model="form.name" />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="edit-slug">Slug</Label>
                            <Input id="edit-slug" v-model="form.slug" />
                            <InputError :message="form.errors.slug" />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="edit-description">Description</Label>
                        <Input
                            id="edit-description"
                            v-model="form.description"
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                    <div class="grid gap-2">
                        <!-- The banner (#292): on the ticket page, the ticket
                             PDF, the public event page and every mail. -->
                        <MediaPicker
                            v-model="form.bannerMediaId"
                            label="Banner"
                            :media="props.media"
                            :brand-id="props.event.brandId"
                            :can-upload="canManageMedia"
                        />
                        <p class="text-xs text-text-muted">
                            A wide picture — it heads the ticket, the public
                            event page and every mail about this event.
                        </p>
                        <InputError :message="form.errors.bannerMediaId" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <DateField
                            id="edit-starts-date"
                            v-model="startsOn"
                            label="Starts on"
                            :error="form.errors.startsAt"
                        />
                        <TimeField
                            id="edit-starts-time"
                            v-model="startsAtTime"
                            label="Starts at"
                            hint="The event's own clock, not yours."
                        />
                        <DateField
                            id="edit-ends-date"
                            v-model="endsOn"
                            label="Ends on"
                            :error="form.errors.endsAt"
                        />
                        <TimeField
                            id="edit-ends-time"
                            v-model="endsAtTime"
                            label="Ends at"
                            hint="The event's own clock, not yours."
                        />
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="edit-tz">Timezone</Label>
                            <Input
                                id="edit-tz"
                                v-model="form.timezone"
                                aria-describedby="edit-tz-hint edit-tz-error"
                            />
                            <p
                                id="edit-tz-hint"
                                class="text-xs text-text-muted"
                            >
                                The zone the two clocks above are read in
                                (ADR-0027) — an IANA name such as Europe/Berlin.
                            </p>
                            <InputError
                                id="edit-tz-error"
                                :message="form.errors.timezone"
                            />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="edit-visibility">Visibility</Label>
                            <select
                                id="edit-visibility"
                                v-model="form.visibility"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                            >
                                <option value="public">Public</option>
                                <option value="unlisted">Unlisted</option>
                            </select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="edit-reentry">Re-entry policy</Label>
                            <select
                                id="edit-reentry"
                                v-model="form.reentryPolicy"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                            >
                                <option value="unlimited">Unlimited</option>
                                <option value="once_per_day">
                                    Once per day
                                </option>
                                <option value="once_only">Once only</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="edit-attendee-list">Attendee list</Label>
                        <select
                            id="edit-attendee-list"
                            v-model="form.attendeeListVisibility"
                            class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                            aria-describedby="edit-attendee-list-hint"
                        >
                            <option value="off">Off</option>
                            <option value="members">
                                Count for everyone, names for signed-in members
                            </option>
                            <option value="public">Names for everyone</option>
                        </select>
                        <p
                            id="edit-attendee-list-hint"
                            class="text-sm text-text-muted"
                        >
                            Only confirmed registrants who opted in on their
                            profile are ever named, by username.
                        </p>
                        <InputError
                            :message="form.errors.attendeeListVisibility"
                        />
                    </div>
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader><CardTitle>Days</CardTitle></CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <div
                        v-for="(day, index) in form.days"
                        :key="index"
                        class="flex items-end gap-2"
                    >
                        <DateField
                            :id="`edit-day-${index}-date`"
                            v-model="day.date"
                            label="Date"
                            class="w-44"
                        />
                        <div class="grid flex-1 gap-2">
                            <Label :for="`edit-day-${index}-label`">
                                Label (optional)
                            </Label>
                            <Input
                                :id="`edit-day-${index}-label`"
                                :model-value="day.label ?? ''"
                                @update:model-value="
                                    day.label = String($event) || null
                                "
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="removeDay(index)"
                        >
                            <Trash2 class="size-4 text-danger" />
                        </Button>
                    </div>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        class="self-start"
                        @click="addDay"
                    >
                        <Plus class="size-4" />
                        Add day
                    </Button>
                    <InputError :message="form.errors.days" />
                </CardContent>
            </Card>

            <div class="flex items-center gap-3 self-start">
                <Button type="submit" :disabled="form.processing">
                    Save changes
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

        <Card class="border-border bg-surface">
            <CardHeader><CardTitle>Venues</CardTitle></CardHeader>
            <CardContent class="flex flex-col gap-4">
                <p
                    v-if="props.venues.length === 0"
                    class="text-sm text-text-muted"
                >
                    No venue assigned — online-only event.
                </p>
                <div
                    v-for="venue in props.venues"
                    :key="venue.id"
                    class="flex items-center gap-3"
                >
                    <span class="font-medium">{{ venue.name }}</span>
                    <span class="text-sm text-text-muted">{{
                        venue.city
                    }}</span>
                    <span
                        v-if="venue.isPrimary"
                        class="rounded-full bg-accent/15 px-2 py-0.5 text-xs text-accent"
                    >
                        primary
                    </span>
                    <Button
                        v-else
                        type="button"
                        variant="ghost"
                        size="sm"
                        title="Make primary"
                        @click="promoteVenue(venue.id)"
                    >
                        <Star class="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="removeVenue(venue.id)"
                    >
                        <Trash2 class="size-4 text-danger" />
                    </Button>
                </div>

                <form
                    v-if="props.availableVenues.length > 0"
                    class="flex items-end gap-2"
                    @submit.prevent="submitVenue"
                >
                    <div class="grid flex-1 gap-1">
                        <Label for="assign-venue" class="text-xs"
                            >Assign venue</Label
                        >
                        <select
                            id="assign-venue"
                            v-model="venueToAssign"
                            class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                        >
                            <option
                                v-for="venue in props.availableVenues"
                                :key="venue.id"
                                :value="venue.id"
                            >
                                {{ venue.name }} — {{ venue.city }}
                            </option>
                        </select>
                    </div>
                    <label
                        class="flex items-center gap-1 pb-2 text-sm text-text-muted"
                    >
                        <input v-model="assignAsPrimary" type="checkbox" />
                        primary
                    </label>
                    <Button type="submit" variant="secondary">Assign</Button>
                </form>
            </CardContent>
        </Card>
    </div>
</template>

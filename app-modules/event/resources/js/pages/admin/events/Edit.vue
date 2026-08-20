<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Plus, Star, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
 * (ADR-0027) — edited as text, never through a Date picker that would
 * re-interpret them in the browser's zone.
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
        predecessorEventId: string | null;
    };
    days: { date: string; label: string | null }[];
    venues: { id: string; name: string; city: string; isPrimary: boolean }[];
    availableVenues: { id: string; name: string; city: string }[];
};

const props = defineProps<Props>();

const form = useForm({
    name: props.event.name,
    slug: props.event.slug,
    description: props.event.description,
    startsAt: props.event.startsAt,
    endsAt: props.event.endsAt,
    timezone: props.event.timezone,
    visibility: props.event.visibility,
    reentryPolicy: props.event.reentryPolicy,
    predecessorEventId: props.event.predecessorEventId,
    days: [...props.days],
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
                    <div class="grid grid-cols-3 gap-4">
                        <div class="grid gap-2">
                            <Label for="edit-starts">Starts (local)</Label>
                            <Input id="edit-starts" v-model="form.startsAt" />
                            <InputError :message="form.errors.startsAt" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="edit-ends">Ends (local)</Label>
                            <Input id="edit-ends" v-model="form.endsAt" />
                            <InputError :message="form.errors.endsAt" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="edit-tz">Timezone</Label>
                            <Input id="edit-tz" v-model="form.timezone" />
                            <InputError :message="form.errors.timezone" />
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
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader><CardTitle>Days</CardTitle></CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <div
                        v-for="(day, index) in form.days"
                        :key="index"
                        class="flex items-center gap-2"
                    >
                        <Input
                            v-model="day.date"
                            placeholder="2027-05-14"
                            class="w-40"
                        />
                        <Input
                            :model-value="day.label ?? ''"
                            placeholder="Label (optional)"
                            @update:model-value="
                                day.label = String($event) || null
                            "
                        />
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

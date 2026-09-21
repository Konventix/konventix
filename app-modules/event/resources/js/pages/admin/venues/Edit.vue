<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAdminCapabilities } from '@/composables/useAdminCapabilities';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import {
    index as venuesIndex,
    update as updateVenue,
} from '@/routes/admin/venues';
import { show as showVenue } from '@/routes/venue';
import VenueImageList from '../../../components/admin/VenueImageList.vue';
import VenuePlacesFields from '../../../components/admin/VenuePlacesFields.vue';
import type { VenueForm } from '../../../lib/venueForm';

/**
 * Everything about a venue that the index's create dialog is too small for:
 * the description, both pairs of coordinates (the building and its
 * entrance), how to get there, where to park, where to unload, the seat
 * plan, and the pictures its public page shows.
 *
 * The shape is `VenueData` (ADR-0009) — the page hand-writes no payload
 * type. Opening times ride along untouched: they have no editor yet, and
 * dropping them from the form would delete them on the first save.
 */
type Props = {
    venue: { id: string; brandId: string } & Konventix.Event.Data.VenueData;
    media: Konventix.Platform.Data.MediaItemData[];
};

const props = defineProps<Props>();
const { canManageMedia } = useAdminCapabilities();

const form = useForm<VenueForm>({
    name: props.venue.name,
    slug: props.venue.slug,
    description: props.venue.description,
    addressLine1: props.venue.addressLine1,
    addressLine2: props.venue.addressLine2,
    postalCode: props.venue.postalCode,
    city: props.venue.city,
    countryCode: props.venue.countryCode,
    latitude: props.venue.latitude,
    longitude: props.venue.longitude,
    entryLatitude: props.venue.entryLatitude,
    entryLongitude: props.venue.entryLongitude,
    accessNotes: props.venue.accessNotes,
    publicTransport: props.venue.publicTransport,
    seatPlanReference: props.venue.seatPlanReference,
    parkingSpots: props.venue.parkingSpots.map((place) => ({ ...place })),
    loadingZones: props.venue.loadingZones.map((place) => ({ ...place })),
    imageMediaIds: [...props.venue.imageMediaIds],
    primaryMediaId: props.venue.primaryMediaId,
    openingTimes: props.venue.openingTimes,
});

useUnsavedChanges(() => form.isDirty);

function submit(): void {
    form.put(updateVenue(props.venue.id).url, { preserveScroll: true });
}

/** An empty coordinate box means "not pinned", never zero. */
function toCoordinate(value: string | number | null): number | null {
    const text = String(value).trim();

    return text === '' || Number.isNaN(Number(text)) ? null : Number(text);
}

function textOrNull(value: string | number | null): string | null {
    return String(value) || null;
}
</script>

<template>
    <Head :title="`Edit — ${props.venue.name}`" />

    <div class="flex max-w-3xl flex-col gap-6">
        <AdminPageHeader
            :title="props.venue.name"
            description="Address, arrival, pictures — what the venue's public page shows"
        >
            <div class="flex items-center gap-4 text-sm text-text-muted">
                <a
                    v-if="props.venue.slug"
                    :href="showVenue(props.venue.slug).url"
                    class="hover:text-text"
                >
                    Public page
                </a>
                <Link :href="venuesIndex().url" class="hover:text-text">
                    All venues
                </Link>
            </div>
        </AdminPageHeader>

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <Card class="border-border bg-surface">
                <CardHeader><CardTitle>Details</CardTitle></CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="venue-name">Name</Label>
                            <Input id="venue-name" v-model="form.name" />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="venue-slug">Slug</Label>
                            <Input
                                id="venue-slug"
                                :model-value="form.slug ?? ''"
                                aria-describedby="venue-slug-hint"
                                @update:model-value="
                                    form.slug = textOrNull($event)
                                "
                            />
                            <p
                                id="venue-slug-hint"
                                class="text-xs text-text-muted"
                            >
                                The venue's public address. Left empty, it is
                                derived from the name.
                            </p>
                            <InputError :message="form.errors.slug" />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="venue-address1">Street and number</Label>
                        <Input
                            id="venue-address1"
                            v-model="form.addressLine1"
                        />
                        <InputError :message="form.errors.addressLine1" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="venue-address2">
                            Address line 2 (optional)
                        </Label>
                        <Input
                            id="venue-address2"
                            :model-value="form.addressLine2 ?? ''"
                            @update:model-value="
                                form.addressLine2 = textOrNull($event)
                            "
                        />
                        <InputError :message="form.errors.addressLine2" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="venue-postal">Postal code</Label>
                            <Input
                                id="venue-postal"
                                v-model="form.postalCode"
                            />
                            <InputError :message="form.errors.postalCode" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="venue-city">City</Label>
                            <Input id="venue-city" v-model="form.city" />
                            <InputError :message="form.errors.city" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="venue-country">Country code</Label>
                            <Input
                                id="venue-country"
                                v-model="form.countryCode"
                                maxlength="2"
                            />
                            <InputError :message="form.errors.countryCode" />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader><CardTitle>Description</CardTitle></CardHeader>
                <CardContent class="grid gap-2">
                    <Label for="venue-description">
                        What this place is, in the visitor's words
                    </Label>
                    <textarea
                        id="venue-description"
                        :value="form.description ?? ''"
                        rows="5"
                        class="rounded-md border border-border bg-surface p-3 text-sm"
                        @input="
                            form.description = textOrNull(
                                ($event.target as HTMLTextAreaElement).value,
                            )
                        "
                    ></textarea>
                    <InputError :message="form.errors.description" />
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader><CardTitle>Coordinates</CardTitle></CardHeader>
                <CardContent class="grid gap-4 sm:grid-cols-2">
                    <div
                        v-for="field in [
                            { key: 'latitude', label: 'Building latitude' },
                            { key: 'longitude', label: 'Building longitude' },
                            {
                                key: 'entryLatitude',
                                label: 'Entrance latitude',
                            },
                            {
                                key: 'entryLongitude',
                                label: 'Entrance longitude',
                            },
                        ] as const"
                        :key="field.key"
                        class="grid gap-2"
                    >
                        <Label :for="`venue-${field.key}`">
                            {{ field.label }}
                        </Label>
                        <Input
                            :id="`venue-${field.key}`"
                            type="number"
                            step="any"
                            :model-value="form[field.key] ?? ''"
                            @update:model-value="
                                form[field.key] = toCoordinate($event)
                            "
                        />
                        <InputError :message="form.errors[field.key]" />
                    </div>
                    <p class="text-xs text-text-muted sm:col-span-2">
                        The entrance is what "Open in maps" sends a visitor to;
                        without it the building's own pin is used.
                    </p>
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader><CardTitle>Getting there</CardTitle></CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="grid gap-2">
                        <Label for="venue-transport">Public transport</Label>
                        <textarea
                            id="venue-transport"
                            :value="form.publicTransport ?? ''"
                            rows="3"
                            class="rounded-md border border-border bg-surface p-3 text-sm"
                            @input="
                                form.publicTransport = textOrNull(
                                    ($event.target as HTMLTextAreaElement)
                                        .value,
                                )
                            "
                        ></textarea>
                        <InputError :message="form.errors.publicTransport" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="venue-access">Access notes</Label>
                        <textarea
                            id="venue-access"
                            :value="form.accessNotes ?? ''"
                            rows="3"
                            class="rounded-md border border-border bg-surface p-3 text-sm"
                            @input="
                                form.accessNotes = textOrNull(
                                    ($event.target as HTMLTextAreaElement)
                                        .value,
                                )
                            "
                        ></textarea>
                        <InputError :message="form.errors.accessNotes" />
                    </div>
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader><CardTitle>Parking spots</CardTitle></CardHeader>
                <CardContent>
                    <VenuePlacesFields
                        v-model="form.parkingSpots"
                        item-label="Parking spot"
                        field-prefix="parkingSpots"
                        :errors="form.errors"
                    />
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader><CardTitle>Loading zones</CardTitle></CardHeader>
                <CardContent>
                    <VenuePlacesFields
                        v-model="form.loadingZones"
                        item-label="Loading zone"
                        field-prefix="loadingZones"
                        :errors="form.errors"
                    />
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader>
                    <CardTitle>Seat plan reference</CardTitle>
                </CardHeader>
                <CardContent class="grid gap-2">
                    <Label for="venue-seat-plan">
                        How the hall's seating is named here
                    </Label>
                    <Input
                        id="venue-seat-plan"
                        :model-value="form.seatPlanReference ?? ''"
                        @update:model-value="
                            form.seatPlanReference = textOrNull($event)
                        "
                    />
                    <InputError :message="form.errors.seatPlanReference" />
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader><CardTitle>Images</CardTitle></CardHeader>
                <CardContent>
                    <VenueImageList
                        v-model:ids="form.imageMediaIds"
                        v-model:primary="form.primaryMediaId"
                        :media="props.media"
                        :brand-id="props.venue.brandId"
                        :can-upload="canManageMedia"
                    />
                    <InputError :message="form.errors.imageMediaIds" />
                    <InputError :message="form.errors.primaryMediaId" />
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
    </div>
</template>

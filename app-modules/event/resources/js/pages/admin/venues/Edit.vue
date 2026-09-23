<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
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
import VenueAddressFields from '../../../components/admin/VenueAddressFields.vue';
import VenueImageList from '../../../components/admin/VenueImageList.vue';
import VenueOpeningTimesFields from '../../../components/admin/VenueOpeningTimesFields.vue';
import VenuePlacesFields from '../../../components/admin/VenuePlacesFields.vue';
import VenueTextArea from '../../../components/admin/VenueTextArea.vue';
import type { VenueForm } from '../../../lib/venueForm';

/**
 * Everything about a venue that the index's create dialog is too small for:
 * the description, both pairs of coordinates (the building and its
 * entrance), how to get there, where to park, where to unload, the seat
 * plan, and the pictures its public page shows.
 *
 * The shape is `VenueData` (ADR-0009) — the page hand-writes no payload
 * type. Every repeatable list on it is its own component: the two place
 * lists, the pictures, and the opening times of the site.
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
    openingTimes: props.venue.openingTimes.map((period) => ({ ...period })),
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

/** The four coordinate boxes, labelled from the catalog at setup time. */
const coordinateFields = [
    { key: 'latitude', label: trans('Building latitude') },
    { key: 'longitude', label: trans('Building longitude') },
    { key: 'entryLatitude', label: trans('Entrance latitude') },
    { key: 'entryLongitude', label: trans('Entrance longitude') },
] as const;

function textOrNull(value: string | number | null): string | null {
    return String(value) || null;
}
</script>

<template>
    <Head :title="$t('Edit — :name', { name: props.venue.name })" />

    <div class="flex max-w-3xl flex-col gap-6">
        <AdminPageHeader
            :title="props.venue.name"
            :description="
                $t(
                    'Address, arrival, pictures — what the venue\'s public page shows',
                )
            "
        >
            <div class="flex items-center gap-4 text-sm text-text-muted">
                <a
                    v-if="props.venue.slug"
                    :href="showVenue(props.venue.slug).url"
                    class="hover:text-text"
                >
                    {{ $t('Public page') }}
                </a>
                <Link :href="venuesIndex().url" class="hover:text-text">
                    {{ $t('All venues') }}
                </Link>
            </div>
        </AdminPageHeader>

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <Card class="border-border bg-surface">
                <CardHeader
                    ><CardTitle>{{ $t('Details') }}</CardTitle></CardHeader
                >
                <CardContent>
                    <VenueAddressFields
                        v-model:name="form.name"
                        v-model:slug="form.slug"
                        v-model:address-line1="form.addressLine1"
                        v-model:address-line2="form.addressLine2"
                        v-model:postal-code="form.postalCode"
                        v-model:city="form.city"
                        v-model:country-code="form.countryCode"
                        :errors="form.errors"
                    />
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader
                    ><CardTitle>{{ $t('Description') }}</CardTitle></CardHeader
                >
                <CardContent>
                    <VenueTextArea
                        id="venue-description"
                        v-model="form.description"
                        :label="
                            $t('What this place is, in the visitor\'s words')
                        "
                        :rows="5"
                        :error="form.errors.description"
                    />
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader
                    ><CardTitle>{{ $t('Coordinates') }}</CardTitle></CardHeader
                >
                <CardContent class="grid gap-4 sm:grid-cols-2">
                    <div
                        v-for="field in coordinateFields"
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
                        {{
                            $t(
                                'The entrance is what "Open in maps" sends a visitor to; without it the building\'s own pin is used.',
                            )
                        }}
                    </p>
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader
                    ><CardTitle>{{
                        $t('Getting there')
                    }}</CardTitle></CardHeader
                >
                <CardContent class="flex flex-col gap-4">
                    <VenueTextArea
                        id="venue-transport"
                        v-model="form.publicTransport"
                        :label="$t('Public transport')"
                        :error="form.errors.publicTransport"
                    />
                    <VenueTextArea
                        id="venue-access"
                        v-model="form.accessNotes"
                        :label="$t('Access notes')"
                        :error="form.errors.accessNotes"
                    />
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader
                    ><CardTitle>{{
                        $t('Opening times')
                    }}</CardTitle></CardHeader
                >
                <CardContent>
                    <VenueOpeningTimesFields
                        v-model="form.openingTimes"
                        :errors="form.errors"
                    />
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader
                    ><CardTitle>{{
                        $t('Parking spots')
                    }}</CardTitle></CardHeader
                >
                <CardContent>
                    <VenuePlacesFields
                        v-model="form.parkingSpots"
                        :item-label="$t('Parking spot')"
                        field-prefix="parkingSpots"
                        :errors="form.errors"
                    />
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader
                    ><CardTitle>{{
                        $t('Loading zones')
                    }}</CardTitle></CardHeader
                >
                <CardContent>
                    <VenuePlacesFields
                        v-model="form.loadingZones"
                        :item-label="$t('Loading zone')"
                        field-prefix="loadingZones"
                        :errors="form.errors"
                    />
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader>
                    <CardTitle>{{ $t('Seat plan reference') }}</CardTitle>
                </CardHeader>
                <CardContent class="grid gap-2">
                    <Label for="venue-seat-plan">
                        {{ $t("How the hall's seating is named here") }}
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
                <CardHeader
                    ><CardTitle>{{ $t('Images') }}</CardTitle></CardHeader
                >
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
                    {{ $t('Save changes') }}
                </Button>
                <span
                    v-if="form.isDirty"
                    role="status"
                    class="text-sm text-warning"
                >
                    {{ $t('Unsaved changes') }}
                </span>
            </div>
        </form>
    </div>
</template>

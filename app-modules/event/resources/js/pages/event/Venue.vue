<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ExternalLink, MapPin } from '@lucide/vue';
import { computed } from 'vue';
import VenueCarousel from '../../components/VenueCarousel.vue';

/**
 * The public venue page. Every section is optional and renders only when
 * the operator filled it in — a venue with nothing but an address is an
 * address, not a page of empty headings.
 *
 * "Open in maps" is a plain OpenStreetMap link built by the controller, not
 * an embedded map: no third-party frame on a public page, and nothing to
 * consent to.
 */
type Place = { label: string; address: string | null; mapsUrl: string | null };

type Props = {
    venue: {
        name: string;
        description: string | null;
        addressLine1: string;
        addressLine2: string | null;
        postalCode: string;
        city: string;
        countryCode: string;
        accessNotes: string | null;
        publicTransport: string | null;
        seatPlanReference: string | null;
        mapsUrl: string | null;
    };
    images: { url: string; alt: string | null }[];
    openingTimes: { dayOfWeek: number; opensAt: string; closesAt: string }[];
    parkingSpots: Place[];
    loadingZones: Place[];
};

const props = defineProps<Props>();

const weekdays = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];

function weekday(dayOfWeek: number): string {
    return weekdays[dayOfWeek - 1] ?? `Day ${dayOfWeek}`;
}

/** The two place lists, and only the ones that have anything in them. */
const placeLists = computed(() =>
    [
        { key: 'parking', title: 'Parking', places: props.parkingSpots },
        {
            key: 'loading',
            title: 'Loading zones',
            places: props.loadingZones,
        },
    ].filter((list) => list.places.length > 0),
);
</script>

<template>
    <Head :title="props.venue.name" />

    <div
        class="mx-auto flex w-full max-w-3xl flex-col gap-10 p-6 text-text lg:p-8"
    >
        <VenueCarousel
            v-if="props.images.length > 0"
            :images="props.images"
            :label="props.venue.name"
        />

        <header class="flex flex-col gap-3">
            <h1 class="text-4xl font-semibold">{{ props.venue.name }}</h1>
            <p class="flex items-start gap-2 text-text-muted">
                <MapPin class="mt-1 size-4 shrink-0" />
                <span>
                    {{ props.venue.addressLine1 }}
                    <template v-if="props.venue.addressLine2">
                        , {{ props.venue.addressLine2 }}
                    </template>
                    <br />
                    {{ props.venue.postalCode }} {{ props.venue.city }},
                    {{ props.venue.countryCode }}
                </span>
            </p>
            <a
                v-if="props.venue.mapsUrl"
                :href="props.venue.mapsUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="flex w-fit items-center gap-1 text-sm text-accent hover:underline"
            >
                Open in maps
                <ExternalLink class="size-3.5" aria-hidden="true" />
                <span class="sr-only">(opens in a new tab)</span>
            </a>
        </header>

        <section
            v-if="props.venue.description"
            class="flex flex-col gap-2"
            aria-labelledby="venue-about"
        >
            <h2 id="venue-about" class="text-lg font-medium">About</h2>
            <p class="max-w-prose whitespace-pre-line text-text-muted">
                {{ props.venue.description }}
            </p>
        </section>

        <section
            v-if="props.venue.publicTransport || props.venue.accessNotes"
            class="flex flex-col gap-3"
            aria-labelledby="venue-getting-there"
        >
            <h2 id="venue-getting-there" class="text-lg font-medium">
                Getting there
            </h2>
            <p
                v-if="props.venue.publicTransport"
                class="max-w-prose whitespace-pre-line text-text-muted"
            >
                {{ props.venue.publicTransport }}
            </p>
            <p
                v-if="props.venue.accessNotes"
                class="max-w-prose whitespace-pre-line text-text-muted"
            >
                {{ props.venue.accessNotes }}
            </p>
        </section>

        <section
            v-for="list in placeLists"
            :key="list.key"
            class="flex flex-col gap-2"
            :aria-labelledby="`venue-${list.key}`"
        >
            <h2 :id="`venue-${list.key}`" class="text-lg font-medium">
                {{ list.title }}
            </h2>
            <ul class="flex flex-col gap-3">
                <li
                    v-for="place in list.places"
                    :key="place.label"
                    class="flex flex-col gap-0.5"
                >
                    <span class="font-medium">{{ place.label }}</span>
                    <span v-if="place.address" class="text-sm text-text-muted">
                        {{ place.address }}
                    </span>
                    <a
                        v-if="place.mapsUrl"
                        :href="place.mapsUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="flex w-fit items-center gap-1 text-sm text-accent hover:underline"
                    >
                        Open in maps
                        <ExternalLink class="size-3.5" aria-hidden="true" />
                        <span class="sr-only">
                            for {{ place.label }} (opens in a new tab)
                        </span>
                    </a>
                </li>
            </ul>
        </section>

        <section
            v-if="props.openingTimes.length > 0"
            class="flex flex-col gap-2"
            aria-labelledby="venue-opening-times"
        >
            <h2 id="venue-opening-times" class="text-lg font-medium">
                Opening times
            </h2>
            <!--
                datetime-raw: period.opensAt
                datetime-raw: period.closesAt
                An opening time is the site's own wall clock, like an event's
                (ADR-0027) — printed as written, never moved into the
                reader's zone.
            -->
            <ul class="flex flex-col gap-1 text-text-muted">
                <li
                    v-for="period in props.openingTimes"
                    :key="`${period.dayOfWeek}-${period.opensAt}`"
                    class="flex gap-3"
                >
                    <span class="w-28 shrink-0 text-text">
                        {{ weekday(period.dayOfWeek) }}
                    </span>
                    <span class="tabular-nums">
                        {{ period.opensAt }} – {{ period.closesAt }}
                    </span>
                </li>
            </ul>
        </section>

        <section
            v-if="props.venue.seatPlanReference"
            class="flex flex-col gap-2"
            aria-labelledby="venue-seat-plan"
        >
            <h2 id="venue-seat-plan" class="text-lg font-medium">Seat plan</h2>
            <p class="max-w-prose text-text-muted">
                {{ props.venue.seatPlanReference }}
            </p>
        </section>
    </div>
</template>

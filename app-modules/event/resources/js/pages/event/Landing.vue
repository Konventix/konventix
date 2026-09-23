<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarDays, MapPin } from '@lucide/vue';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';
import { show as showVenue } from '@/routes/venue';

/**
 * Public event landing page. Times are the organizer's promised wall-clock
 * values plus zone (ADR-0027) — printed as-is, never through `new Date()`.
 *
 * What a visitor can *do* here is not this page's business. Modules
 * contribute it through the `event.landing` slot (ADR-0003 enforcement point
 * 3) and resolve by name through the registered-component convention, so
 * Event imports nothing from Registration. A contribution whose component is
 * not in this build renders nothing rather than erroring.
 */
type SlotContribution = {
    module: string;
    component: string;
    props: Record<string, unknown>;
};
type Props = {
    event: {
        name: string;
        description: string | null;
        /** The event's banner from the media library, null for none (#292). */
        bannerUrl: string | null;
        bannerAlt: string | null;
        state: string;
        startsAt: string;
        endsAt: string;
        timezone: string;
    };
    days: { date: string; sequence: number; label: string | null }[];
    venues: {
        /** Links to the venue's own public page (Task 18). */
        slug: string;
        name: string;
        addressLine1: string;
        postalCode: string;
        city: string;
        isPrimary: boolean;
        /** The venue's primary picture, null when it has none. */
        imageUrl: string | null;
        imageAlt: string | null;
    }[];
    slots: SlotContribution[];
};

const props = defineProps<Props>();
</script>

<template>
    <!--
    datetime-raw: event.startsAt
    datetime-raw: event.endsAt
    datetime-raw: props.event.startsAt
    datetime-raw: props.event.endsAt
    An event time is the organizer's wall clock plus a zone label (ADR-0027),
    not an instant: it is printed exactly as promised, in the zone the label
    names, and must not be converted to the reader's own.
-->
    <Head :title="props.event.name" />

    <div
        class="mx-auto flex w-full max-w-3xl flex-col gap-10 p-6 text-text lg:p-8"
    >
        <header class="flex flex-col gap-3">
            <!-- The hero (#292): the same picture the ticket and the mails
                 wear. A plain <img>, not a background, so it has alt text
                 and prints. -->
            <img
                v-if="props.event.bannerUrl"
                :src="props.event.bannerUrl"
                :alt="props.event.bannerAlt ?? props.event.name"
                class="aspect-[3/1] w-full rounded-xl object-cover"
            />
            <p
                v-if="props.event.state === 'cancelled'"
                class="self-start rounded-full bg-danger/15 px-3 py-1 text-sm font-medium text-danger"
            >
                {{ $t('Called off') }}
            </p>
            <h1 class="text-4xl font-semibold">{{ props.event.name }}</h1>
            <p class="flex items-center gap-2 text-text-muted">
                <CalendarDays class="size-4" />
                {{ props.event.startsAt }} – {{ props.event.endsAt }}
                <span class="text-xs">({{ props.event.timezone }})</span>
            </p>
            <p
                v-if="props.event.description"
                class="max-w-prose text-text-muted"
            >
                {{ props.event.description }}
            </p>
        </header>

        <template v-for="slot in props.slots" :key="slot.component">
            <component
                :is="resolveRegisteredComponent(slot.component)"
                v-if="resolveRegisteredComponent(slot.component)"
                v-bind="slot.props"
            />
        </template>

        <section v-if="props.days.length > 0" class="flex flex-col gap-2">
            <h2 class="text-lg font-medium">{{ $t('Days') }}</h2>
            <ul class="flex flex-col gap-3">
                <li
                    v-for="day in props.days"
                    :key="day.date"
                    class="flex items-baseline gap-3 text-text-muted"
                >
                    <span class="w-16 shrink-0 font-medium text-text">{{
                        $t('Day :number', { number: String(day.sequence) })
                    }}</span>
                    <span class="w-28 shrink-0 tabular-nums">{{
                        day.date
                    }}</span>
                    <template v-if="day.label">
                        <span aria-hidden="true">—</span>
                        <span class="truncate">{{ day.label }}</span>
                    </template>
                </li>
            </ul>
        </section>

        <section v-if="props.venues.length > 0" class="flex flex-col gap-2">
            <h2 class="text-lg font-medium">
                {{ $tChoice('{1} Venue|[2,*] Venues', props.venues.length) }}
            </h2>
            <ul class="flex flex-col gap-3">
                <li
                    v-for="venue in props.venues"
                    :key="venue.slug"
                    class="flex items-start gap-3"
                >
                    <img
                        v-if="venue.imageUrl"
                        :src="venue.imageUrl"
                        :alt="venue.imageAlt ?? venue.name"
                        class="size-16 shrink-0 rounded-md object-cover"
                    />
                    <MapPin v-else class="mt-1 size-4 text-text-muted" />
                    <div>
                        <p class="font-medium">
                            <Link
                                :href="showVenue(venue.slug).url"
                                class="hover:underline"
                            >
                                {{ venue.name }}
                            </Link>
                            <span
                                v-if="
                                    venue.isPrimary && props.venues.length > 1
                                "
                                class="ml-1 rounded-full bg-accent/15 px-2 py-0.5 text-xs text-accent"
                            >
                                {{ $t('Main venue') }}
                            </span>
                        </p>
                        <p class="text-sm text-text-muted">
                            {{ venue.addressLine1 }}, {{ venue.postalCode }}
                            {{ venue.city }}
                        </p>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>

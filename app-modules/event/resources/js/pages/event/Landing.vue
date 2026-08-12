<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CalendarDays, MapPin } from '@lucide/vue';
import PublicTopbar from '@/components/PublicTopbar.vue';

/**
 * Public event landing page. Times are the organizer's promised wall-clock
 * values plus zone (ADR-0027) — printed as-is, never through `new Date()`.
 */
type Props = {
    event: {
        name: string;
        description: string | null;
        state: string;
        startsAt: string;
        endsAt: string;
        timezone: string;
    };
    days: { date: string; sequence: number; label: string | null }[];
    venues: {
        name: string;
        addressLine1: string;
        postalCode: string;
        city: string;
        isPrimary: boolean;
    }[];
};

const props = defineProps<Props>();

defineOptions({ layout: null });
</script>

<template>
    <Head :title="props.event.name" />

    <div class="flex min-h-screen flex-col bg-surface text-text">
        <PublicTopbar :name="$page.props.name" />

        <main class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-10 p-6 lg:p-8">
            <header class="flex flex-col gap-3">
                <p
                    v-if="props.event.state === 'cancelled'"
                    class="self-start rounded-full bg-danger/15 px-3 py-1 text-sm font-medium text-danger"
                >
                    Cancelled
                </p>
                <h1 class="text-4xl font-semibold">{{ props.event.name }}</h1>
                <p class="flex items-center gap-2 text-text-muted">
                    <CalendarDays class="size-4" />
                    {{ props.event.startsAt }} – {{ props.event.endsAt }}
                    <span class="text-xs">({{ props.event.timezone }})</span>
                </p>
                <p v-if="props.event.description" class="max-w-prose text-text-muted">
                    {{ props.event.description }}
                </p>
            </header>

            <section v-if="props.days.length > 0" class="flex flex-col gap-2">
                <h2 class="text-lg font-medium">Days</h2>
                <ul class="flex flex-col gap-1">
                    <li
                        v-for="day in props.days"
                        :key="day.date"
                        class="flex items-baseline gap-3 text-text-muted"
                    >
                        <span class="font-medium text-text">Day {{ day.sequence }}</span>
                        <span>{{ day.date }}</span>
                        <span v-if="day.label">— {{ day.label }}</span>
                    </li>
                </ul>
            </section>

            <section v-if="props.venues.length > 0" class="flex flex-col gap-2">
                <h2 class="text-lg font-medium">
                    {{ props.venues.length === 1 ? 'Venue' : 'Venues' }}
                </h2>
                <ul class="flex flex-col gap-3">
                    <li
                        v-for="venue in props.venues"
                        :key="venue.name"
                        class="flex items-start gap-2"
                    >
                        <MapPin class="mt-1 size-4 text-text-muted" />
                        <div>
                            <p class="font-medium">
                                {{ venue.name }}
                                <span
                                    v-if="venue.isPrimary && props.venues.length > 1"
                                    class="ml-1 rounded-full bg-accent/15 px-2 py-0.5 text-xs text-accent"
                                >
                                    Main venue
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
        </main>
    </div>
</template>

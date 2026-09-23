<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarDays, MapPin } from '@lucide/vue';
import { show as showEvent } from '@/routes/event';

/**
 * The brand's public event list — the "Events" entry in the topbar.
 *
 * Times print exactly as the organizer promised them, with the zone beside
 * them (ADR-0027): these are wall-clock strings, never parsed through
 * `new Date()`. The upcoming/past split is the server's, for the same
 * reason — only it can turn a wall-clock value into a real instant.
 */
type EventSummary = {
    slug: string;
    name: string;
    description: string | null;
    state: string;
    startsAt: string;
    endsAt: string;
    timezone: string;
    venue: string | null;
};

const props = defineProps<{
    upcoming: EventSummary[];
    past: EventSummary[];
}>();
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
    <Head :title="$t('Events')" />

    <div
        class="mx-auto flex w-full max-w-3xl flex-col gap-10 p-6 text-text lg:p-8"
    >
        <h1 class="text-3xl font-semibold">{{ $t('Events') }}</h1>

        <p
            v-if="props.upcoming.length === 0 && props.past.length === 0"
            class="text-sm text-text-muted"
        >
            {{ $t('Nothing announced yet.') }}
        </p>

        <section
            v-if="props.upcoming.length > 0"
            class="flex flex-col gap-4"
            aria-labelledby="upcoming-events"
        >
            <h2 id="upcoming-events" class="text-lg font-medium">
                {{ $t('Upcoming') }}
            </h2>

            <ul class="flex flex-col gap-4">
                <li v-for="event in props.upcoming" :key="event.slug">
                    <Link
                        :href="showEvent(event.slug).url"
                        class="flex flex-col gap-2 rounded-xl border border-border bg-surface-raised p-5 transition hover:border-accent"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-xl font-semibold">
                                {{ event.name }}
                            </h3>
                            <span
                                v-if="event.state === 'cancelled'"
                                class="rounded-full bg-danger/15 px-2 py-0.5 text-xs font-medium text-danger"
                            >
                                {{ $t('Called off') }}
                            </span>
                            <span
                                v-else-if="event.state === 'running'"
                                class="rounded-full bg-success/15 px-2 py-0.5 text-xs font-medium text-success"
                            >
                                {{ $t('Happening now') }}
                            </span>
                        </div>

                        <p
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-text-muted"
                        >
                            <span class="flex items-center gap-2">
                                <CalendarDays class="size-4 shrink-0" />
                                {{ event.startsAt }} – {{ event.endsAt }}
                                <span class="text-xs"
                                    >({{ event.timezone }})</span
                                >
                            </span>
                            <span
                                v-if="event.venue"
                                class="flex items-center gap-2"
                            >
                                <MapPin class="size-4 shrink-0" />
                                {{ event.venue }}
                            </span>
                        </p>

                        <p
                            v-if="event.description"
                            class="max-w-prose text-sm text-text-muted"
                        >
                            {{ event.description }}
                        </p>
                    </Link>
                </li>
            </ul>
        </section>

        <section
            v-if="props.past.length > 0"
            class="flex flex-col gap-3"
            aria-labelledby="past-events"
        >
            <h2 id="past-events" class="text-lg font-medium">
                {{ $t('Past') }}
            </h2>

            <ul class="flex flex-col">
                <li
                    v-for="event in props.past"
                    :key="event.slug"
                    class="border-b border-border last:border-b-0"
                >
                    <Link
                        :href="showEvent(event.slug).url"
                        class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3 hover:text-accent"
                    >
                        <span class="font-medium">{{ event.name }}</span>
                        <span class="text-sm text-text-muted">
                            {{ event.startsAt }}
                        </span>
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>

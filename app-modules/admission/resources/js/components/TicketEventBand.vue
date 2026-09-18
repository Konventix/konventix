<script setup lang="ts">
import { CalendarDays, MapPin } from '@lucide/vue';

/**
 * The top of the ticket (#292 §4.5): the event's name over its banner when
 * it has one, otherwise over the brand's accent. A gradient scrim keeps
 * the name legible on any picture in either theme; the picture itself is a
 * real `<img>` underneath, so it has alt text and prints.
 */
const props = defineProps<{
    eventName: string | null;
    eventWhen: string | null;
    venueLabel: string | null;
    bannerUrl: string | null;
    bannerAlt: string | null;
}>();
</script>

<template>
    <header
        class="relative overflow-hidden rounded-t-2xl bg-accent text-on-accent"
        :class="props.bannerUrl ? 'min-h-48' : 'min-h-28'"
    >
        <img
            v-if="props.bannerUrl"
            :src="props.bannerUrl"
            :alt="props.bannerAlt ?? props.eventName ?? ''"
            class="absolute inset-0 size-full object-cover"
        />
        <div
            v-if="props.bannerUrl"
            class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/30 to-transparent"
            aria-hidden="true"
        />
        <div
            class="relative flex h-full flex-col justify-end gap-1 p-5"
            :class="props.bannerUrl ? 'text-white' : ''"
        >
            <h1 class="text-2xl leading-tight font-semibold">
                {{ props.eventName ?? 'Your event' }}
            </h1>
            <!-- datetime-raw: props.eventWhen — the organizer's wall clock, formatted on the server (ADR-0027) -->
            <p
                v-if="props.eventWhen"
                class="flex items-center gap-2 text-sm opacity-90"
            >
                <CalendarDays class="size-4 shrink-0" aria-hidden="true" />
                {{ props.eventWhen }}
            </p>
            <p
                v-if="props.venueLabel"
                class="flex items-center gap-2 text-sm opacity-90"
            >
                <MapPin class="size-4 shrink-0" aria-hidden="true" />
                {{ props.venueLabel }}
            </p>
        </div>
    </header>
</template>

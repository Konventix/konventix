<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarDays, MapPin } from '@lucide/vue';
import { computed, useId } from 'vue';
import { show as showEvent } from '@/routes/event';

/**
 * The top of the ticket (#292 §4.5): the event's name over its banner when
 * it has one, otherwise over the brand's accent. A gradient scrim keeps
 * the name legible on any picture in either theme; the picture itself is a
 * real `<img>` underneath, so it has alt text and prints.
 *
 * With an `eventSlug` the whole band is one `<Link>` to the event's landing
 * page (the house pattern of `event/Index.vue`), one tab stop named by the
 * heading; without one it is a plain block.
 */
const props = defineProps<{
    eventName: string | null;
    eventSlug: string | null;
    eventWhen: string | null;
    venueLabel: string | null;
    bannerUrl: string | null;
    bannerAlt: string | null;
}>();

const headingId = useId();

const linkAttributes = computed(() =>
    props.eventSlug
        ? {
              href: showEvent(props.eventSlug).url,
              'aria-labelledby': headingId,
          }
        : {},
);
</script>

<template>
    <header
        class="relative overflow-hidden rounded-t-2xl bg-accent text-on-accent"
    >
        <component
            :is="props.eventSlug ? Link : 'div'"
            v-bind="linkAttributes"
            class="relative block"
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
                class="absolute inset-0 bg-gradient-to-t from-surface-inverted/80 via-surface-inverted/30 to-transparent"
                aria-hidden="true"
            />
            <div
                class="relative flex h-full flex-col justify-end gap-1 p-5"
                :class="props.bannerUrl ? 'text-text-inverted' : ''"
            >
                <h1
                    :id="headingId"
                    class="text-2xl leading-tight font-semibold"
                >
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
        </component>
    </header>
</template>

<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, ref, useId, watch } from 'vue';
import {
    clampSlide,
    nextSlide,
    previousSlide,
    slideForKey,
    slideStatus,
} from '../lib/carousel';

/**
 * A venue's pictures, hand-written rather than pulled in as a dependency
 * (Task 18's decision). Accessibility is the whole reason it is worth
 * writing by hand (ADR-0055: a public page targets WCAG 2.1 AA):
 *
 * - the region carries `aria-roledescription="carousel"` and a label, so a
 *   screen reader announces what it is rather than "group";
 * - each slide is a labelled group saying which of how many it is;
 * - the previous/next buttons and the dots have accessible names, and the
 *   dots say which one is current with `aria-current`;
 * - a separate `aria-live="polite"` status announces the move, because the
 *   slide itself is hidden from the tree until it is the current one;
 * - arrow keys, Home and End move the carousel while it has focus, and a
 *   key it does not own is left alone so Tab still tabs;
 * - the transition is dropped under `prefers-reduced-motion` — by CSS, so
 *   it follows the reader's setting rather than a snapshot of it.
 *
 * A single picture renders as a picture: no buttons, no dots, no live
 * region to announce a move that cannot happen.
 */
type Slide = { url: string; alt: string | null };

const props = defineProps<{
    images: Slide[];
    /** What the carousel is of — the venue's name. */
    label: string;
}>();

const uid = useId();
const current = ref(0);

watch(
    () => props.images.length,
    (count) => {
        current.value = clampSlide(current.value, count);
    },
);

const count = computed(() => props.images.length);
const status = computed(() => slideStatus(current.value, count.value));

function onKeydown(keyEvent: KeyboardEvent): void {
    const target = slideForKey(keyEvent.key, current.value, count.value);

    if (target === null) {
        return;
    }

    keyEvent.preventDefault();
    current.value = target;
}
</script>

<template>
    <figure v-if="count === 1" class="flex flex-col gap-2">
        <img
            :src="props.images[0].url"
            :alt="props.images[0].alt ?? props.label"
            class="aspect-[3/2] w-full rounded-xl object-cover"
        />
    </figure>

    <section
        v-else-if="count > 1"
        class="venue-carousel flex flex-col gap-3"
        aria-roledescription="carousel"
        :aria-label="`Pictures of ${props.label}`"
        tabindex="0"
        @keydown="onKeydown"
    >
        <div class="relative overflow-hidden rounded-xl">
            <div
                v-for="(image, index) in props.images"
                :key="image.url"
                role="group"
                aria-roledescription="slide"
                :aria-label="`${index + 1} of ${count}`"
                :aria-hidden="index === current ? undefined : 'true'"
                :inert="index === current ? undefined : true"
                class="venue-carousel__slide"
                :class="index === current ? 'opacity-100' : 'hidden opacity-0'"
            >
                <img
                    :src="image.url"
                    :alt="image.alt ?? `${props.label} — picture ${index + 1}`"
                    class="aspect-[3/2] w-full object-cover"
                />
            </div>

            <button
                type="button"
                class="absolute top-1/2 left-2 -translate-y-1/2 rounded-full bg-surface/80 p-2 text-text hover:bg-surface"
                @click="current = previousSlide(current, count)"
            >
                <ChevronLeft class="size-5" />
                <span class="sr-only">Previous picture</span>
            </button>
            <button
                type="button"
                class="absolute top-1/2 right-2 -translate-y-1/2 rounded-full bg-surface/80 p-2 text-text hover:bg-surface"
                @click="current = nextSlide(current, count)"
            >
                <ChevronRight class="size-5" />
                <span class="sr-only">Next picture</span>
            </button>
        </div>

        <div class="flex items-center justify-center gap-2">
            <button
                v-for="(image, index) in props.images"
                :key="`dot-${image.url}`"
                type="button"
                class="size-2.5 rounded-full"
                :class="index === current ? 'bg-accent' : 'bg-border'"
                :aria-current="index === current ? 'true' : undefined"
                @click="current = index"
            >
                <span class="sr-only">
                    Show picture {{ index + 1 }} of {{ count }}
                </span>
            </button>
        </div>

        <p :id="`${uid}-status`" role="status" class="sr-only">{{ status }}</p>
    </section>
</template>

<style scoped>
.venue-carousel__slide {
    transition: opacity 200ms ease-in-out;
}

@media (prefers-reduced-motion: reduce) {
    .venue-carousel__slide {
        transition: none;
    }
}
</style>

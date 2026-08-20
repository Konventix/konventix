<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    heading: string;
    subheading: string | null;
    ctaLabel: string | null;
    ctaUrl: string | null;
    imageUrl: string | null;
    imageAlt: string | null;
}>();

// The media item's own alt text when the library has one; the heading is the
// fallback so the image is never announced as unlabelled.
const imageAltText = computed(() => props.imageAlt ?? props.heading);
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border border-border bg-surface-raised"
    >
        <div class="grid gap-8 p-8 lg:grid-cols-2 lg:items-center lg:p-12">
            <div class="flex flex-col gap-4">
                <h1
                    class="text-3xl font-bold tracking-tight text-text sm:text-4xl"
                >
                    {{ heading }}
                </h1>
                <p
                    v-if="subheading"
                    class="max-w-prose text-lg text-text-muted"
                >
                    {{ subheading }}
                </p>
                <div v-if="ctaLabel && ctaUrl">
                    <Link
                        :href="ctaUrl"
                        class="inline-flex items-center rounded-md bg-accent px-5 py-2.5 text-sm font-medium text-on-accent hover:bg-accent/90"
                    >
                        {{ ctaLabel }}
                    </Link>
                </div>
            </div>
            <img
                v-if="imageUrl"
                :src="imageUrl"
                :alt="imageAltText"
                class="aspect-video w-full rounded-lg border border-border object-cover"
            />
        </div>
    </section>
</template>

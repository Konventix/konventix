<script setup lang="ts">
/**
 * The one header every operator-console page opens with (ADR-0029): title,
 * an optional one-line description, and a right-aligned actions slot.
 * Pages compose this instead of hand-rolling an `<h1>`, so the console
 * reads as one application rather than fourteen.
 */
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex min-w-0 flex-col gap-1">
            <h1 class="text-2xl font-semibold text-text">{{ title }}</h1>
            <p v-if="description" class="text-sm text-text-muted">
                {{ description }}
            </p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <slot />
        </div>
    </div>
</template>

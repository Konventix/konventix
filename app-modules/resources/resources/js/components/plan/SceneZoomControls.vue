<script setup lang="ts">
import { Minus, Plus, Scan } from '@lucide/vue';

/**
 * The scene's three 44 px zoom buttons (design §7.4; ADR-0055 target
 * size), overlaid in the scene's bottom-right corner. Split out of
 * `SeatPlanScene.vue` in the 2026-09-21 bugfix round purely for size.
 */
defineEmits<{ zoomIn: []; zoomOut: []; reset: [] }>();
</script>

<template>
    <div class="absolute right-3 bottom-3 flex flex-col gap-1">
        <button
            type="button"
            class="inline-flex size-11 items-center justify-center rounded-md border border-border bg-surface text-text hover:bg-subtle"
            :aria-label="$t('Zoom in')"
            @click="$emit('zoomIn')"
        >
            <Plus class="size-4" />
        </button>
        <button
            type="button"
            class="inline-flex size-11 items-center justify-center rounded-md border border-border bg-surface text-text hover:bg-subtle"
            :aria-label="$t('Zoom out')"
            @click="$emit('zoomOut')"
        >
            <Minus class="size-4" />
        </button>
        <button
            type="button"
            class="inline-flex size-11 items-center justify-center rounded-md border border-border bg-surface text-text hover:bg-subtle"
            :aria-label="$t('Show the whole plan')"
            @click="$emit('reset')"
        >
            <Scan class="size-4" />
        </button>
    </div>
</template>

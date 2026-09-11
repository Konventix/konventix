<script setup lang="ts">
import { Check } from '@lucide/vue';
import { symbolFor } from './symbols';
import { STATE_FILL } from './tokens';

/**
 * The scene's shared SVG `<defs>` (design §7.4): the grid background
 * pattern, the diagonal hatch every ineligible place draws over its fill,
 * one `<symbol>` per category that `PlanPlace.vue`'s `<use>` references by
 * id, and the single `seat-badge-mine` check symbol every "mine" place
 * draws in its corner — state is never colour-only, ADR-0055, and "mine"
 * is the one selectable state, so it gets a symbol instead of the hatch.
 * Split out of `SeatPlanScene.vue` purely for size — none of this is
 * interactive.
 */
type Category = Konventix.Resources.Data.CategoryData;

defineProps<{ categories: Category[] }>();
</script>

<template>
    <defs>
        <pattern
            id="seat-grid"
            width="1000"
            height="1000"
            patternUnits="userSpaceOnUse"
        >
            <path
                d="M 1000 0 L 0 0 0 1000"
                fill="none"
                :stroke="STATE_FILL.outline"
                stroke-width="10"
            />
        </pattern>
        <pattern
            id="seat-hatch"
            width="200"
            height="200"
            patternUnits="userSpaceOnUse"
            patternTransform="rotate(45)"
        >
            <rect
                width="80"
                height="200"
                :fill="STATE_FILL.textMuted"
                opacity="0.35"
            />
        </pattern>
        <symbol
            v-for="category in categories"
            :id="`seat-symbol-${category.id}`"
            :key="category.id"
            viewBox="0 0 24 24"
        >
            <component :is="symbolFor(category.symbol)" />
        </symbol>
        <symbol id="seat-badge-mine" viewBox="0 0 24 24">
            <Check />
        </symbol>
    </defs>
</template>

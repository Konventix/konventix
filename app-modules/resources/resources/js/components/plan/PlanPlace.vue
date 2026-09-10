<script setup lang="ts">
import { computed } from 'vue';
import type { Reason } from './eligibility';
import { isSelectable } from './eligibility';
import { placeLabel } from './labels';
import PlanShape from './PlanShape.vue';
import { STATE_FILL, tokenVar } from './tokens';
import type { Bbox, Point } from './transform';

/**
 * One rendered place inside `SeatPlanScene.vue`'s world `<g>`: its fill by
 * state or category, a hatch pattern for anything not selectable, a corner
 * check badge for "mine" (state is never colour-only, ADR-0055 — "mine" is
 * selectable so it gets no hatch, and its fill alone was the one state
 * this component conveyed by colour only until the review that flagged
 * it), the category's `<use>` symbol, its label once labels are visible,
 * and the invisible enlarged circle that keeps it a 44 px touch target at
 * any zoom. Split out purely to keep the scene's own file near the
 * project's size guideline — the click target, cursor and
 * `data-place-id`/`data-state` attributes Task 40's `SeatPickPageTest`
 * reads stay on the `<g>` the scene renders around this component.
 */
type Element = Konventix.Resources.Data.PlanElementData;
type Category = Konventix.Resources.Data.CategoryData;

const props = defineProps<{
    element: Element;
    origin: Point;
    box: Bbox;
    center: Point;
    category?: Category;
    reason: Reason;
    showLabel: boolean;
    hitRadius: number;
}>();

const fill = computed(() => {
    switch (props.reason) {
        case 'mine':
            return STATE_FILL.mine;
        case 'taken':
            return STATE_FILL.taken;
        case 'unavailable':
            return STATE_FILL.unavailable;
        default:
            return tokenVar(props.category?.token ?? null);
    }
});

const hatched = computed(() => !isSelectable(props.reason));

/** Two thirds the shorter side, so a symbol never overruns a narrow shape. */
const symbolSize = computed(
    () =>
        Math.min(
            props.box.maxX - props.box.minX,
            props.box.maxY - props.box.minY,
        ) * 0.6,
);

/**
 * A small check badge in the place's top-right corner for "mine" — a
 * symbol cue, not another fill, so it survives a colour-vision-impaired
 * or greyscale rendering the way the hatch already does for every
 * non-selectable state. Sized off the same shorter-side measure as the
 * category symbol, at roughly a third of it, with a ring wide enough to
 * read against any category fill.
 */
const badgeRadius = computed(() => symbolSize.value * 0.32);
const badgeCenter = computed(() => ({
    x: props.box.maxX - badgeRadius.value * 1.15,
    y: props.box.minY + badgeRadius.value * 1.15,
}));

/** Never smaller than the shape's own half-diagonal, so a large place is not under-targeted. */
const hitR = computed(() => {
    const half =
        Math.hypot(
            props.box.maxX - props.box.minX,
            props.box.maxY - props.box.minY,
        ) / 2;

    return Math.max(props.hitRadius, half);
});
</script>

<template>
    <PlanShape
        :shape="element.shape"
        :origin="origin"
        :rotation="element.rotation"
        :fill="fill"
        :stroke="
            reason === 'selected'
                ? STATE_FILL.selectedStroke
                : STATE_FILL.outline
        "
        :stroke-width="reason === 'selected' ? 80 : 15"
    />
    <PlanShape
        v-if="hatched"
        :shape="element.shape"
        :origin="origin"
        :rotation="element.rotation"
        fill="url(#seat-hatch)"
    />
    <use
        v-if="category"
        :href="`#seat-symbol-${category.id}`"
        :x="center.x - symbolSize / 2"
        :y="center.y - symbolSize / 2"
        :width="symbolSize"
        :height="symbolSize"
        class="pointer-events-none text-text"
    />
    <g v-if="reason === 'mine'" class="pointer-events-none">
        <circle
            :cx="badgeCenter.x"
            :cy="badgeCenter.y"
            :r="badgeRadius"
            :fill="STATE_FILL.mine"
            :stroke="STATE_FILL.outline"
            stroke-width="15"
        />
        <use
            href="#seat-badge-mine"
            :x="badgeCenter.x - badgeRadius * 0.65"
            :y="badgeCenter.y - badgeRadius * 0.65"
            :width="badgeRadius * 1.3"
            :height="badgeRadius * 1.3"
            class="text-success-contrast"
        />
    </g>
    <text
        v-if="showLabel"
        :x="center.x"
        :y="box.maxY + 280"
        text-anchor="middle"
        font-size="250"
        :fill="STATE_FILL.text"
        class="pointer-events-none"
    >
        {{ placeLabel(element) }}
    </text>
    <circle :cx="center.x" :cy="center.y" :r="hitR" fill="transparent" />
</template>

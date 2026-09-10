<script setup lang="ts">
import { computed } from 'vue';
import {
    absoluteOrigin,
    elementIndex,
    shapeBbox,
    shapeCenter,
} from './geometry';
import PlanShape from './PlanShape.vue';
import { STATE_FILL, tokenVar } from './tokens';
import type { Bbox, Point } from './transform';

/**
 * The scene's non-interactive layer (design §7.4): the millimetre grid,
 * zones (tinted by their category token, with a label), fixtures (tables,
 * counters, walls) and free-text annotations. Split out of
 * `SeatPlanScene.vue` purely for size — nothing here reads state or
 * emits; only `PlanPlace.vue`'s places respond to a pointer.
 */
type Document = Konventix.Resources.Data.PlanDocumentData;
type Element = Konventix.Resources.Data.PlanElementData;
type Placed = { element: Element; origin: Point; center: Point; box: Bbox };

const props = defineProps<{ document: Document }>();

const index = computed(() => elementIndex(props.document));

function place(element: Element): Placed {
    const origin = absoluteOrigin(element, index.value);

    return {
        element,
        origin,
        center: shapeCenter(element.shape, origin),
        box: shapeBbox(element.shape, origin),
    };
}

const zones = computed(() =>
    props.document.elements.filter((e) => e.kind === 'zone').map(place),
);
const fixtures = computed(() =>
    props.document.elements.filter((e) => e.kind === 'fixture').map(place),
);
const annotations = computed(() =>
    props.document.elements.filter((e) => e.kind === 'annotation').map(place),
);
</script>

<template>
    <g>
        <rect
            x="-100000"
            y="-100000"
            width="200000"
            height="200000"
            fill="url(#seat-grid)"
        />

        <g v-for="zone in zones" :key="zone.element.id">
            <PlanShape
                :shape="zone.element.shape"
                :origin="zone.origin"
                :rotation="zone.element.rotation"
                :fill="tokenVar(zone.element.token)"
                :stroke="STATE_FILL.outline"
                :stroke-width="20"
                :opacity="0.12"
            />
            <text
                :x="zone.box.minX + 200"
                :y="zone.box.minY + 500"
                font-size="400"
                :fill="STATE_FILL.text"
            >
                {{ zone.element.label ?? zone.element.ref ?? '' }}
            </text>
        </g>

        <PlanShape
            v-for="fixture in fixtures"
            :key="fixture.element.id"
            :shape="fixture.element.shape"
            :origin="fixture.origin"
            :rotation="fixture.element.rotation"
            :fill="STATE_FILL.zone"
            :stroke="STATE_FILL.outline"
            :stroke-width="20"
        />

        <text
            v-for="note in annotations"
            :key="note.element.id"
            :x="note.origin.x"
            :y="note.origin.y"
            :font-size="note.element.fontSizeMm ?? 300"
            :fill="STATE_FILL.textMuted"
        >
            {{ note.element.text ?? '' }}
        </text>
    </g>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import type { Bbox } from '../geometry';
import { absoluteBbox, documentBbox, hitTest } from '../geometry';
import SeatPlanScene from '../SeatPlanScene.vue';
import type { Point } from './document';
import type { SnapStep } from './snap';
import { snapDelta } from './snap';
import type { PlanStore } from './usePlanStore';

/**
 * The viewer's scene plus the editor overlay (design §8.3). The wrapper
 * captures every pointer-down first: with the pan tool or the middle
 * button it lets the scene pan as it does for participants; otherwise it
 * stops the event and decides — hit an element (select, drag), hit the
 * floor (marquee), or hand the point to the page for the place and
 * annotation tools. A drag is one `move` mutation on pointer-up; while it
 * lasts, ghost boxes in the overlay show where things will land.
 */
type PlaceState = Konventix.Resources.Enums.PlaceState;

type Drag = {
    pointerId: number;
    origin: Point;
    anchor: Point;
    ids: string[];
    offset: Point;
};
type Marquee = { pointerId: number; origin: Point; corner: Point };
type Box = { id: string; box: Bbox };

const props = defineProps<{
    store: PlanStore;
    plan: Konventix.Resources.Data.PlanViewData;
    categories: Konventix.Resources.Data.CategoryData[];
    states: Record<string, PlaceState>;
    snapStep: SnapStep;
}>();

const emit = defineEmits<{ 'canvas-click': [point: Point] }>();

const store = props.store;
const scene = ref<InstanceType<typeof SeatPlanScene> | null>(null);
const drag = ref<Drag | null>(null);
const marquee = ref<Marquee | null>(null);

const scenePlan = computed(() => ({
    ...props.plan,
    document: store.document.value,
}));

const cursorClass = computed(() => {
    switch (store.tool.value) {
        case 'pan':
            return 'cursor-grab';
        case 'delete':
            return 'cursor-not-allowed';
        case 'place':
        case 'annotation':
            return 'cursor-crosshair';
        default:
            return 'cursor-default';
    }
});

/**
 * A point-shaped element still needs something to grab and to ring. Boxes
 * are corner coordinates throughout — `Bbox` is S5's one shape,
 * `{ minX, minY, maxX, maxY }` — so a width is `maxX - minX` and growing a
 * box means pushing its corners apart around its centre.
 */
function padded(box: Bbox): Bbox {
    const growX = Math.max(0, 200 - (box.maxX - box.minX)) / 2;
    const growY = Math.max(0, 200 - (box.maxY - box.minY)) / 2;

    return {
        minX: box.minX - growX,
        minY: box.minY - growY,
        maxX: box.maxX + growX,
        maxY: box.maxY + growY,
    };
}

const selectionBoxes = computed<Box[]>(() =>
    store.selection.value.map((id) => ({
        id,
        box: padded(absoluteBbox(store.document.value, id)),
    })),
);

const ghostBoxes = computed<Box[]>(() => {
    const current = drag.value;

    if (!current || (current.offset.x === 0 && current.offset.y === 0)) {
        return [];
    }

    return current.ids.map((id) => {
        const box = padded(absoluteBbox(store.document.value, id));

        return {
            id,
            box: {
                minX: box.minX + current.offset.x,
                minY: box.minY + current.offset.y,
                maxX: box.maxX + current.offset.x,
                maxY: box.maxY + current.offset.y,
            },
        };
    });
});

const marqueeRect = computed<Bbox | null>(() => {
    const current = marquee.value;

    if (!current) {
        return null;
    }

    return {
        minX: Math.min(current.origin.x, current.corner.x),
        minY: Math.min(current.origin.y, current.corner.y),
        maxX: Math.max(current.origin.x, current.corner.x),
        maxY: Math.max(current.origin.y, current.corner.y),
    };
});

function worldPoint(event: PointerEvent): Point | null {
    return scene.value?.svgPoint(event.clientX, event.clientY) ?? null;
}

function contains(outer: Bbox, inner: Bbox): boolean {
    return (
        inner.minX >= outer.minX &&
        inner.minY >= outer.minY &&
        inner.maxX <= outer.maxX &&
        inner.maxY <= outer.maxY
    );
}

function onPointerDown(event: PointerEvent): void {
    if (store.tool.value === 'pan' || event.button === 1) {
        return;
    }

    if (event.button !== 0) {
        return;
    }

    const point = worldPoint(event);

    if (!point) {
        return;
    }

    event.stopPropagation();
    const hit = hitTest(store.document.value, point);

    if (store.tool.value === 'delete') {
        if (hit !== null) {
            store.removeElements([hit]);
        }

        return;
    }

    if (store.tool.value === 'place' || store.tool.value === 'annotation') {
        emit('canvas-click', point);

        return;
    }

    const target = event.currentTarget as HTMLElement;

    if (hit === null) {
        if (!event.shiftKey) {
            store.clearSelection();
        }

        marquee.value = {
            pointerId: event.pointerId,
            origin: point,
            corner: point,
        };
        target.setPointerCapture(event.pointerId);

        return;
    }

    if (event.shiftKey) {
        store.toggleSelected(hit);

        return;
    }

    if (!store.selection.value.includes(hit)) {
        store.select([hit]);
    }

    const ids = store.selectedElements.value
        .filter((element) => !element.locked)
        .map((element) => element.id);

    if (ids.length === 0) {
        return;
    }

    const anchorBox = absoluteBbox(store.document.value, ids[0]);
    drag.value = {
        pointerId: event.pointerId,
        origin: point,
        anchor: { x: anchorBox.minX, y: anchorBox.minY },
        ids,
        offset: { x: 0, y: 0 },
    };
    target.setPointerCapture(event.pointerId);
}

function onPointerMove(event: PointerEvent): void {
    const point = worldPoint(event);

    if (!point) {
        return;
    }

    if (drag.value && drag.value.pointerId === event.pointerId) {
        drag.value.offset = snapDelta(
            drag.value.anchor,
            {
                x: point.x - drag.value.origin.x,
                y: point.y - drag.value.origin.y,
            },
            props.snapStep,
        );

        return;
    }

    if (marquee.value && marquee.value.pointerId === event.pointerId) {
        marquee.value.corner = point;
    }
}

function onPointerUp(event: PointerEvent): void {
    if (drag.value && drag.value.pointerId === event.pointerId) {
        const { ids, offset } = drag.value;
        drag.value = null;

        if (offset.x !== 0 || offset.y !== 0) {
            store.moveElements(ids, offset.x, offset.y);
        }

        return;
    }

    if (marquee.value && marquee.value.pointerId === event.pointerId) {
        const rect = marqueeRect.value;
        marquee.value = null;

        if (!rect || (rect.maxX === rect.minX && rect.maxY === rect.minY)) {
            return;
        }

        const inside = store.document.value.elements
            .filter((element) =>
                contains(rect, absoluteBbox(store.document.value, element.id)),
            )
            .map((element) => element.id);

        store.select(
            event.shiftKey ? [...store.selection.value, ...inside] : inside,
        );
    }
}

function focusBbox(bbox: Bbox): void {
    scene.value?.fitToBbox(bbox);
}

function fitAll(): void {
    const bbox = documentBbox(store.document.value);

    if (bbox) {
        scene.value?.fitToBbox(bbox);
    }
}

defineExpose({ focusBbox, fitAll });
</script>

<template>
    <div
        class="relative h-full w-full touch-none select-none"
        :class="cursorClass"
        @pointerdown.capture="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
        @pointercancel="onPointerUp"
    >
        <!--
            The scene's `availability` prop is the state map; the editor has
            no claim and no picker selection, so it passes neither and the
            scene's defaults (`claim: null`, `selectedIds: []`) apply — the
            rings below are the editor's own selection.
        -->
        <SeatPlanScene
            ref="scene"
            :plan="scenePlan"
            :categories="categories"
            :availability="states"
        >
            <template #overlay>
                <g class="pointer-events-none">
                    <rect
                        v-for="entry in selectionBoxes"
                        :key="`sel-${entry.id}`"
                        :x="entry.box.minX - 40"
                        :y="entry.box.minY - 40"
                        :width="entry.box.maxX - entry.box.minX + 80"
                        :height="entry.box.maxY - entry.box.minY + 80"
                        class="fill-none stroke-accent"
                        stroke-width="2"
                        vector-effect="non-scaling-stroke"
                    />
                    <rect
                        v-for="entry in ghostBoxes"
                        :key="`ghost-${entry.id}`"
                        :x="entry.box.minX"
                        :y="entry.box.minY"
                        :width="entry.box.maxX - entry.box.minX"
                        :height="entry.box.maxY - entry.box.minY"
                        class="fill-accent/15 stroke-accent"
                        stroke-width="1.5"
                        stroke-dasharray="6 4"
                        vector-effect="non-scaling-stroke"
                    />
                    <rect
                        v-if="marqueeRect"
                        :x="marqueeRect.minX"
                        :y="marqueeRect.minY"
                        :width="marqueeRect.maxX - marqueeRect.minX"
                        :height="marqueeRect.maxY - marqueeRect.minY"
                        class="fill-accent/10 stroke-accent"
                        stroke-width="1"
                        vector-effect="non-scaling-stroke"
                    />
                </g>
            </template>
        </SeatPlanScene>
    </div>
</template>

<script setup lang="ts">
import { Minus, Plus, Scan } from '@lucide/vue';
import { useElementSize } from '@vueuse/core';
import { computed, reactive, ref, watch } from 'vue';
import type { Reason } from './eligibility';
import { isSelectable, placeReason } from './eligibility';
import {
    absoluteOrigin,
    contentBbox,
    elementIndex,
    hitRadiusWorld,
    shapeBbox,
    shapeCenter,
} from './geometry';
import { labelsVisible } from './labels';
import PlanBackdrop from './PlanBackdrop.vue';
import PlanDefs from './PlanDefs.vue';
import PlanPlace from './PlanPlace.vue';
import type { Bbox, Point, View } from './transform';
import { fitToBbox, minZoomFor, worldTransform } from './transform';
import { useScenePointer } from './useScenePointer';

/**
 * The plan as an SVG scene (design §7.4): a fixed viewBox of the plan's
 * size in mm, one world `<g transform>`, pointer pan/pinch/wheel zoom
 * (`useScenePointer.ts`), a static backdrop (`PlanBackdrop.vue`) under the
 * interactive places (`PlanPlace.vue`), labels only above a legibility
 * threshold, and an invisible enlarged hit shape so a place stays a 44 px
 * target at any zoom. The math is transform.ts / geometry.ts; this file
 * wires props and events to it.
 *
 * Not the keyboard path: the SVG is an image with a pointer, the list view
 * beside it is the same choice as buttons. Every ineligible place still
 * renders, hatched and with a text state in the list.
 */
type Plan = Konventix.Resources.Data.PlanViewData;
type Category = Konventix.Resources.Data.CategoryData;
type Claim = Konventix.Resources.Data.SeatClaimData;
type Element = Konventix.Resources.Data.PlanElementData;
type PlaceState = Konventix.Resources.Enums.PlaceState;

type Placed = { element: Element; origin: Point; center: Point; box: Bbox };
type RenderedPlace = Placed & {
    category: Category | undefined;
    reason: Reason;
};

/**
 * `claim` and `selectedIds` are optional so the same scene serves the
 * console's plan editor (S7), which has no claim and draws its own
 * selection in the `#overlay` slot. With no claim there is no eligibility
 * to compute — the scene then colours a place by the state it was handed
 * (see `reasonFor`). The picker page passes all five.
 */
const props = withDefaults(
    defineProps<{
        plan: Plan;
        categories: Category[];
        availability: Record<string, PlaceState>;
        claim?: Claim | null;
        selectedIds?: string[];
    }>(),
    { claim: null, selectedIds: () => [] },
);

const emit = defineEmits<{
    toggle: [placeId: string];
    hint: [reason: Reason];
}>();

const svgRef = ref<SVGSVGElement | null>(null);
const { width: clientWidth, height: clientHeight } = useElementSize(svgRef);

const viewport = computed(() => ({
    width: props.plan.widthMm,
    height: props.plan.heightMm,
}));

/** Screen pixels per viewBox unit under `preserveAspectRatio="xMidYMid meet"`. */
const pxPerUnit = computed(() => {
    if (!clientWidth.value || !clientHeight.value) {
        return 0;
    }

    return Math.min(
        clientWidth.value / viewport.value.width,
        clientHeight.value / viewport.value.height,
    );
});

const index = computed(() => elementIndex(props.plan.document));
const bbox = computed(() => contentBbox(props.plan.document));
const minZoom = computed(() => minZoomFor(bbox.value, viewport.value));

function initialView(): View {
    const box = bbox.value ?? {
        minX: 0,
        minY: 0,
        maxX: viewport.value.width,
        maxY: viewport.value.height,
    };

    return fitToBbox(box, viewport.value, { minZoom: minZoom.value });
}

const view = reactive<View>(initialView());

watch(
    () => props.plan.id,
    () => Object.assign(view, initialView()),
);

const {
    onPointerDown,
    onPointerMove,
    onPointerUp,
    onWheel,
    zoomStep,
    svgPoint,
    wasDragged,
} = useScenePointer(svgRef, view, viewport, minZoom, pxPerUnit);

const transform = computed(() => worldTransform(view, viewport.value));
const showLabels = computed(() => labelsVisible(view.zoom, pxPerUnit.value));
const hitRadius = computed(() => hitRadiusWorld(pxPerUnit.value, view.zoom));
const categoryById = computed(
    () => new Map(props.categories.map((category) => [category.id, category])),
);

/**
 * With a claim the picker's rules decide (eligibility.ts, mirroring
 * `AssignPlacesToRegistration`). Without one — the editor — nothing is
 * ineligible: the state map alone says whether a place is free, held or
 * booked, so the operator sees occupancy without every seat being hatched
 * as "not in your category".
 */
function reasonFor(element: Element): Reason {
    const claim = props.claim;

    if (claim === null) {
        if (props.selectedIds.includes(element.id)) {
            return 'selected';
        }

        switch (props.availability[element.id]) {
            case 'mine':
                return 'mine';
            case 'held':
            case 'booked':
                return 'taken';
            default:
                return 'free';
        }
    }

    return placeReason({
        placeId: element.id,
        resourceTypeId: element.resourceTypeId,
        state: props.availability[element.id],
        claimTypeIds: claim.resourceTypeIds,
        selectedIds: props.selectedIds,
        quantity: claim.quantity,
    });
}

const places = computed<RenderedPlace[]>(() =>
    props.plan.document.elements
        .filter((e) => e.kind === 'place')
        .map((element) => {
            const origin = absoluteOrigin(element, index.value);

            return {
                element,
                origin,
                center: shapeCenter(element.shape, origin),
                box: shapeBbox(element.shape, origin),
                category:
                    element.resourceTypeId === null
                        ? undefined
                        : categoryById.value.get(element.resourceTypeId),
                reason: reasonFor(element),
            };
        }),
);

function onPlaceClick(item: RenderedPlace): void {
    if (wasDragged()) {
        return;
    }

    if (isSelectable(item.reason)) {
        emit('toggle', item.element.id);

        return;
    }

    emit('hint', item.reason);
}

function resetView(): void {
    Object.assign(view, initialView());
}

/** Move the view so `box` fills it — the editor's "fit" button and "reveal this element". */
function fitToBboxExposed(box: Bbox): void {
    Object.assign(
        view,
        fitToBbox(box, viewport.value, { minZoom: minZoom.value }),
    );
}

defineExpose({ svgPoint, fitToBbox: fitToBboxExposed });
</script>

<template>
    <div
        class="relative overflow-hidden rounded-xl border border-border bg-surface-raised"
    >
        <svg
            ref="svgRef"
            :viewBox="`0 0 ${viewport.width} ${viewport.height}`"
            preserveAspectRatio="xMidYMid meet"
            class="block h-[60vh] w-full touch-none select-none"
            role="img"
            :aria-label="`Seat plan ${plan.name}. Drag to pan, pinch or scroll to zoom. Use the list below to choose by keyboard.`"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerUp"
            @wheel.prevent="onWheel"
        >
            <PlanDefs :categories="categories" />

            <g :transform="transform">
                <PlanBackdrop :document="plan.document" />

                <g
                    v-for="item in places"
                    :key="item.element.id"
                    :data-place-id="item.element.id"
                    :data-state="item.reason"
                    :class="
                        isSelectable(item.reason)
                            ? 'cursor-pointer'
                            : 'cursor-not-allowed'
                    "
                    @click="onPlaceClick(item)"
                >
                    <PlanPlace
                        :element="item.element"
                        :origin="item.origin"
                        :box="item.box"
                        :center="item.center"
                        :category="item.category"
                        :reason="item.reason"
                        :show-label="showLabels"
                        :hit-radius="hitRadius"
                    />
                </g>

                <!--
                    Anything a host draws on top of the plan — the editor's
                    selection rings, drag ghosts and marquee (S7). It sits
                    inside the world transform, so its children are written
                    in plan millimetres like every other element, and it is
                    last so it paints over the places.
                -->
                <slot name="overlay" />
            </g>
        </svg>

        <div class="absolute right-3 bottom-3 flex flex-col gap-1">
            <button
                type="button"
                class="inline-flex size-11 items-center justify-center rounded-md border border-border bg-surface text-text hover:bg-subtle"
                aria-label="Zoom in"
                @click="zoomStep(1.25)"
            >
                <Plus class="size-4" />
            </button>
            <button
                type="button"
                class="inline-flex size-11 items-center justify-center rounded-md border border-border bg-surface text-text hover:bg-subtle"
                aria-label="Zoom out"
                @click="zoomStep(0.8)"
            >
                <Minus class="size-4" />
            </button>
            <button
                type="button"
                class="inline-flex size-11 items-center justify-center rounded-md border border-border bg-surface text-text hover:bg-subtle"
                aria-label="Show the whole plan"
                @click="resetView"
            >
                <Scan class="size-4" />
            </button>
        </div>
    </div>
</template>

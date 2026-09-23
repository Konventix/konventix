<script setup lang="ts">
import { useElementSize } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { computed, reactive, ref, watch } from 'vue';
import type { Reason } from './eligibility';
import { isSelectable, placeReason, stateText } from './eligibility';
import {
    absoluteOrigin,
    contentBbox,
    elementIndex,
    hitRadiusWorld,
    hitTestPlaces,
    shapeBbox,
    shapeCenter,
} from './geometry';
import { CONTAINER_LABEL_MM, labelsVisible, placeLabel } from './labels';
import PlanBackdrop from './PlanBackdrop.vue';
import PlanDefs from './PlanDefs.vue';
import PlanPlace from './PlanPlace.vue';
import SceneZoomControls from './SceneZoomControls.vue';
import type { Bbox, Point, View } from './transform';
import { fitToBbox, minZoomFor, worldTransform } from './transform';
import { useScenePointer } from './useScenePointer';

/**
 * The plan as an SVG scene (design §7.4): a fixed viewBox of the plan's
 * size in mm, one world `<g transform>`, pointer pan/pinch/wheel zoom
 * (`useScenePointer.ts`), a static backdrop (`PlanBackdrop.vue`) under the
 * interactive places (`PlanPlace.vue`), and group and fixture labels only
 * above a legibility threshold. The math is transform.ts / geometry.ts;
 * this file wires props and events to it.
 *
 * A tap chooses a place by hit test, not by a listener per place
 * (2026-09-21 bugfix round): the composable takes pointer capture for pan
 * and pinch, which retargets the browser's `click` to the `<svg>`, so the
 * per-place `@click` this replaced never fired. `useScenePointer` reports
 * an undragged primary-button `pointerup` as a world point and
 * `hitTestPlaces` picks the nearest place within the 44 px radius
 * (`hitRadiusWorld`) or under the point — no enlarged invisible hit
 * circles, whose paint order let one seat swallow its neighbours' taps.
 * Hovering a place shows its label as the native `<title>` tooltip.
 *
 * Not the keyboard path: the SVG is an image with a pointer; the list the
 * picker's Accessibility button reveals is the same choice as buttons.
 * Every ineligible place still renders, hatched and with a text state in
 * the list.
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
} = useScenePointer(svgRef, view, viewport, minZoom, pxPerUnit, onTap);

const transform = computed(() => worldTransform(view, viewport.value));
const showLabels = computed(() =>
    labelsVisible(view.zoom, pxPerUnit.value, CONTAINER_LABEL_MM),
);
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

function onTap(point: Point): void {
    const item = hitTestPlaces(places.value, point, hitRadius.value);

    if (item === null) {
        return;
    }

    if (isSelectable(item.reason)) {
        emit('toggle', item.element.id);

        return;
    }

    emit('hint', item.reason);
}

/** The hover tooltip and accessible name of one place: its label, and with a claim its state. */
function placeTitle(item: RenderedPlace): string {
    const label = placeLabel(item.element);

    return props.claim === null
        ? label
        : trans(':place: :state', {
              place: label,
              state: stateText(item.reason),
          });
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
            :aria-label="
                $t(
                    'Seating plan :name. Click or tap a place to choose it; drag to pan, pinch or scroll to zoom. The Accessibility button above lists every place as a button for keyboard use.',
                    { name: plan.name },
                )
            "
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerUp"
            @wheel.prevent="onWheel"
        >
            <PlanDefs :categories="categories" />

            <g :transform="transform">
                <PlanBackdrop
                    :document="plan.document"
                    :show-labels="showLabels"
                />

                <g
                    v-for="item in places"
                    :key="item.element.id"
                    :data-place-id="item.element.id"
                    :data-state="item.reason"
                    :aria-label="placeTitle(item)"
                    :class="
                        isSelectable(item.reason)
                            ? 'cursor-pointer'
                            : 'cursor-not-allowed'
                    "
                >
                    <title>{{ placeTitle(item) }}</title>
                    <PlanPlace
                        :element="item.element"
                        :origin="item.origin"
                        :box="item.box"
                        :center="item.center"
                        :category="item.category"
                        :reason="item.reason"
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

        <SceneZoomControls
            @zoom-in="zoomStep(1.25)"
            @zoom-out="zoomStep(0.8)"
            @reset="resetView"
        />
    </div>
</template>

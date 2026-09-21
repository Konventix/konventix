<script setup lang="ts">
import { computed } from 'vue';
import type { Reason } from './eligibility';
import { hintFor, isSelectable, placeReason, stateText } from './eligibility';
import { planTree } from './geometry';
import { placeLabel } from './labels';

/**
 * The same choice as the scene, as buttons (design §7.4; ADR-0055): zone
 * → group → place, `aria-pressed` for the selection, `aria-disabled`
 * rather than `disabled` so an ineligible place still explains itself on
 * activation instead of being skipped by the tab order. Every place the
 * scene renders is here, with its state as text.
 */
type Plan = Konventix.Resources.Data.PlanViewData;
type Category = Konventix.Resources.Data.CategoryData;
type Claim = Konventix.Resources.Data.SeatClaimData;
type Element = Konventix.Resources.Data.PlanElementData;
type PlaceState = Konventix.Resources.Enums.PlaceState;

const props = defineProps<{
    plan: Plan;
    categories: Category[];
    claim: Claim;
    availability: Record<string, PlaceState>;
    selectedIds: string[];
}>();

const emit = defineEmits<{
    toggle: [placeId: string];
    hint: [reason: Reason];
}>();

const tree = computed(() => planTree(props.plan.document));
const categoryName = computed(
    () => new Map(props.categories.map((c) => [c.id, c.name])),
);

function reasonOf(element: Element): Reason {
    return placeReason({
        placeId: element.id,
        resourceTypeId: element.resourceTypeId,
        state: props.availability[element.id],
        claimTypeIds: props.claim.resourceTypeIds,
        selectedIds: props.selectedIds,
        quantity: props.claim.quantity,
    });
}

function onActivate(element: Element): void {
    const reason = reasonOf(element);

    if (isSelectable(reason)) {
        emit('toggle', element.id);

        return;
    }

    emit('hint', reason);
}

function describe(element: Element): string {
    const reason = reasonOf(element);
    const category = element.resourceTypeId
        ? categoryName.value.get(element.resourceTypeId)
        : undefined;

    return [category, stateText(reason), hintFor(reason)]
        .filter(Boolean)
        .join('. ');
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <p v-if="tree.length === 0" class="text-sm text-text-muted">
            This plan has no places yet.
        </p>
        <section
            v-for="zone in tree"
            :key="zone.id || 'loose'"
            class="flex flex-col gap-3"
            :aria-labelledby="`plan-zone-${plan.id}-${zone.id || 'loose'}`"
        >
            <h3
                :id="`plan-zone-${plan.id}-${zone.id || 'loose'}`"
                class="text-base font-medium"
            >
                {{ zone.label }}
            </h3>
            <div
                v-for="group in zone.groups"
                :key="group.id || 'loose'"
                class="flex flex-col gap-2"
            >
                <h4 class="text-sm text-text-muted">{{ group.label }}</h4>
                <ul class="flex flex-wrap gap-2">
                    <li v-for="element in group.places" :key="element.id">
                        <button
                            type="button"
                            class="inline-flex min-h-11 min-w-11 flex-col items-center justify-center rounded-md border px-3 py-1 text-sm aria-disabled:cursor-not-allowed aria-disabled:opacity-60 aria-pressed:border-accent aria-pressed:bg-accent aria-pressed:text-on-accent"
                            :class="
                                reasonOf(element) === 'mine'
                                    ? 'border-success text-success'
                                    : 'border-border'
                            "
                            :aria-pressed="selectedIds.includes(element.id)"
                            :aria-disabled="!isSelectable(reasonOf(element))"
                            :aria-label="`${placeLabel(element)}. ${describe(element)}`"
                            @click="onActivate(element)"
                        >
                            <span class="font-medium">{{
                                placeLabel(element)
                            }}</span>
                            <span class="text-xs">{{
                                stateText(reasonOf(element))
                            }}</span>
                        </button>
                    </li>
                </ul>
            </div>
        </section>
    </div>
</template>

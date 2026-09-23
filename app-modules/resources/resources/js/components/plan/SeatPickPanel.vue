<script setup lang="ts">
import { nextTick, ref } from 'vue';
import { Button } from '@/components/ui/button';
import CategoryLegend from './CategoryLegend.vue';
import type { Reason } from './eligibility';
import PlanListView from './PlanListView.vue';
import SeatPickForm from './SeatPickForm.vue';
import SeatPlanScene from './SeatPlanScene.vue';
import SelectionHints from './SelectionHints.vue';

/**
 * One plan's panel of the picker (design §7.4): the legend, the
 * Accessibility toggle, the scene, the two live regions, the save form
 * and — only while the toggle is on — the list of every place as a
 * button. `Pick.vue` owns the selection and the polled availability; this
 * component owns nothing but whether the list is shown. Split out in the
 * 2026-09-21 bugfix round so the page stays near the project's "extract
 * before ~300 lines" guideline.
 *
 * The toggle is a real `<button aria-pressed aria-expanded
 * aria-controls>` before the scene in DOM order, so a keyboard user meets
 * it before the pointer-only image; the list is off by default (it doubled
 * the page for every sighted visitor) and revealing it moves focus into
 * the section so the next Tab lands on its first place.
 */
type Plan = Konventix.Resources.Data.PlanViewData;
type Category = Konventix.Resources.Data.CategoryData;
type Claim = Konventix.Resources.Data.SeatClaimData;
type PlaceState = Konventix.Resources.Enums.PlaceState;

defineProps<{
    plan: Plan;
    categories: Category[];
    claim: Claim;
    availability: Record<string, PlaceState>;
    selected: string[];
    announcement: string;
    hint: string | null;
    registrationId: string;
    admissionId: string | null;
}>();

defineEmits<{
    toggle: [placeId: string];
    hint: [reason: Reason];
}>();

const showList = ref(false);
const listRef = ref<HTMLElement | null>(null);

async function toggleList(): Promise<void> {
    showList.value = !showList.value;

    if (showList.value) {
        await nextTick();
        listRef.value?.focus();
    }
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <CategoryLegend
                :categories="categories"
                :claim-resource-type-ids="claim.resourceTypeIds"
            />
            <Button
                type="button"
                variant="outline"
                :aria-pressed="showList"
                :aria-expanded="showList"
                aria-controls="plan-list"
                @click="toggleList"
            >
                {{ $t('Accessibility') }}
            </Button>
        </div>

        <SeatPlanScene
            :plan="plan"
            :categories="categories"
            :claim="claim"
            :availability="availability"
            :selected-ids="selected"
            @toggle="$emit('toggle', $event)"
            @hint="$emit('hint', $event)"
        />

        <SelectionHints :announcement="announcement" :hint="hint" />

        <SeatPickForm
            :registration-id="registrationId"
            :admission-id="admissionId"
            :selected="selected"
            :announcement="announcement"
        />

        <section
            v-if="showList"
            id="plan-list"
            ref="listRef"
            tabindex="-1"
            class="flex flex-col gap-3 outline-none"
            aria-labelledby="plan-list-heading"
        >
            <h2 id="plan-list-heading" class="text-lg font-medium">
                {{ $t('Choose from the list') }}
            </h2>
            <PlanListView
                :plan="plan"
                :categories="categories"
                :claim="claim"
                :availability="availability"
                :selected-ids="selected"
                @toggle="$emit('toggle', $event)"
                @hint="$emit('hint', $event)"
            />
        </section>
    </div>
</template>

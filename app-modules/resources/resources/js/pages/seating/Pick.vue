<script setup lang="ts">
import { Form, Head, Link, useHttp } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import SeatAvailabilityController from '@/actions/Konventix/Resources/Http/Controllers/SeatAvailabilityController';
import SeatPickController from '@/actions/Konventix/Resources/Http/Controllers/SeatPickController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { show as showRegistration } from '@/routes/registration/mine';
import CategoryLegend from '../../components/plan/CategoryLegend.vue';
import type { Reason } from '../../components/plan/eligibility';
import {
    categoriesHaveFreePlace,
    firstPlaceIdsError,
    hintFor,
    reconcileSelection,
    toggleSelection,
} from '../../components/plan/eligibility';
import {
    ASSIGNED_BY_ORGANISER_MESSAGE,
    NO_FREE_PLACES_MESSAGE,
    placeLabel,
    selectionAnnouncement,
} from '../../components/plan/labels';
import PlanListView from '../../components/plan/PlanListView.vue';
import PlanTabs from '../../components/plan/PlanTabs.vue';
import SeatPlanScene from '../../components/plan/SeatPlanScene.vue';
import SelectionHints from '../../components/plan/SelectionHints.vue';

/**
 * Choose your seat (design §7.4). One tab per published plan, the SVG
 * scene and the list view side by side over the same selection, hints in
 * a live region, and a plain form post of `placeIds[]` to
 * `SeatPickController@store` — the Action and the exclusion constraint
 * decide, the page only refuses what it can already see.
 *
 * Availability polls every 15 s through `useHttp` against the JSON
 * endpoint while the tab is visible; a selected place somebody else took
 * meanwhile leaves the selection with a spoken hint, so the submit does
 * not fail on something the person could not know.
 *
 * The "nothing free" banner (`soldOut`, 2026-09-10 review fix) is derived
 * from that same polled `availability` map (`categoriesHaveFreePlace`),
 * not a flag computed once at render — that stayed "sold out" after a
 * held place actually freed up. `SeatSummarySlot.vue` has no poll of its
 * own and keeps reading the server's `SeatSummaryData.noFreePlaces`; both
 * render the same `NO_FREE_PLACES_MESSAGE` so the wording cannot drift.
 */
// A bare `type Props = Konventix.Resources.Data.SeatPickerData;` fails the
// SFC compiler's macro type resolver ("Unresolvable type reference"); every
// other page nests a whole Data object as a field instead (e.g.
// `admin/resource-types/Edit.vue`'s `Props.type`). Mirrors `SeatPickerData`.
type Props = {
    registrationId: string;
    claim: Konventix.Resources.Data.SeatClaimData;
    categories: Konventix.Resources.Data.CategoryData[];
    plans: Konventix.Resources.Data.PlanViewData[];
    availability: Konventix.Resources.Data.PlanAvailabilityData;
    currentPlaceIds: string[];
};
type Availability = Konventix.Resources.Data.PlanAvailabilityData;
type PlaceState = Konventix.Resources.Enums.PlaceState;

const props = defineProps<Props>();

/**
 * Item 1 of the 2026-09-10 fix wave (#278): an `assign`-mode claim is the
 * organiser's to place, not the registrant's — the picker itself must not
 * render, only the explanatory state below.
 */
const assignedByOrganiser = computed(
    () => props.claim.seatingMode === 'assign',
);

const activePlanId = ref(props.plans[0]?.id ?? '');
const activePlan = computed(
    () => props.plans.find((plan) => plan.id === activePlanId.value) ?? null,
);

const availability = ref<Record<string, PlaceState>>({
    ...props.availability.places,
});
const selected = ref<string[]>([...props.currentPlaceIds]);
const hint = ref<string | null>(null);

/** Every place of every published plan, category id and all — the fixed set `categoriesHaveFreePlace` checks the live availability of. */
const placeElements = computed(() =>
    props.plans.flatMap((plan) =>
        plan.document.elements.filter((element) => element.kind === 'place'),
    ),
);

const soldOut = computed(
    () =>
        props.plans.length > 0 &&
        props.claim.state === 'unplaced' &&
        !categoriesHaveFreePlace(
            placeElements.value,
            availability.value,
            props.claim.resourceTypeIds,
        ),
);

const refById = computed(() => {
    const map = new Map<string, string>();

    for (const plan of props.plans) {
        for (const element of plan.document.elements) {
            if (element.kind === 'place') {
                map.set(element.id, placeLabel(element));
            }
        }
    }

    return map;
});

const announcement = computed(() =>
    selectionAnnouncement(
        selected.value.map((id) => refById.value.get(id) ?? id),
        props.claim.quantity,
    ),
);

function onToggle(placeId: string): void {
    hint.value = null;
    selected.value = toggleSelection(
        selected.value,
        placeId,
        props.claim.quantity,
    );
}

function onHint(reason: Reason): void {
    hint.value = hintFor(reason, props.claim.quantity);
}

const http = useHttp<Record<string, never>, Availability>();
let timer: ReturnType<typeof setInterval> | undefined;

function refreshAvailability(): void {
    if (document.hidden || http.processing) {
        return;
    }

    http.get(SeatAvailabilityController.show.url(props.registrationId), {
        onSuccess: (response) => {
            availability.value = response.places;
        },
    }).catch(() => undefined);
}

onMounted(() => {
    timer = setInterval(refreshAvailability, 15_000);
});

onBeforeUnmount(() => {
    if (timer) {
        clearInterval(timer);
    }

    http.cancel();
});

watch(availability, (places) => {
    const result = reconcileSelection(selected.value, places);

    if (result.lost.length === 0) {
        return;
    }

    selected.value = result.selected;
    const names = result.lost
        .map((id) => refById.value.get(id) ?? id)
        .join(', ');
    hint.value = `${names} ${result.lost.length === 1 ? 'was' : 'were'} just taken by somebody else.`;
});
</script>

<template>
    <Head title="Choose your seat" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 py-6">
        <div>
            <Link
                :href="showRegistration(props.registrationId)"
                class="mb-4 inline-flex items-center gap-2 text-sm text-text-muted hover:text-text"
            >
                <ArrowLeft class="size-4" />
                Your registration
            </Link>

            <Heading
                as="h1"
                title="Choose your seat"
                :description="
                    props.claim.quantity === 1
                        ? 'Pick one place from the categories your registration includes.'
                        : `Pick up to ${props.claim.quantity} places from the categories your registration includes.`
                "
            />
        </div>

        <!-- An `assign`-mode claim is the organiser's to place: no picker at
             all, only the explanatory state (item 1, fix wave #278). -->
        <p
            v-if="assignedByOrganiser"
            role="status"
            class="rounded-md border border-border bg-surface-raised p-4 text-sm text-text-muted"
        >
            {{ ASSIGNED_BY_ORGANISER_MESSAGE }}
        </p>

        <template v-else>
            <!-- Exactly one of these two banners: `soldOut` requires a published plan. -->
            <p
                v-if="props.plans.length === 0"
                class="text-sm text-text-muted"
                role="status"
            >
                No seat plan has been published for this event yet. Come back
                later.
            </p>

            <template v-else>
                <p
                    v-if="soldOut"
                    role="status"
                    class="rounded-md border border-warning/40 bg-warning/15 p-4 text-sm text-warning-contrast"
                >
                    {{ NO_FREE_PLACES_MESSAGE }}
                </p>

                <PlanTabs
                    v-if="props.plans.length > 1"
                    v-model="activePlanId"
                    :plans="props.plans"
                />

                <div
                    v-if="activePlan"
                    :id="`plan-panel-${activePlan.id}`"
                    role="tabpanel"
                    :aria-labelledby="
                        props.plans.length > 1
                            ? `plan-tab-${activePlan.id}`
                            : undefined
                    "
                    class="flex flex-col gap-6"
                >
                    <CategoryLegend
                        :categories="props.categories"
                        :claim-resource-type-ids="props.claim.resourceTypeIds"
                    />

                    <SeatPlanScene
                        :plan="activePlan"
                        :categories="props.categories"
                        :claim="props.claim"
                        :availability="availability"
                        :selected-ids="selected"
                        @toggle="onToggle"
                        @hint="onHint"
                    />

                    <SelectionHints :announcement="announcement" :hint="hint" />

                    <Form
                        v-bind="
                            SeatPickController.store.form(props.registrationId)
                        "
                        #default="{ errors, processing }"
                        class="flex flex-col gap-4"
                    >
                        <input
                            v-for="id in selected"
                            :key="id"
                            type="hidden"
                            name="placeIds[]"
                            :value="id"
                        />

                        <InputError :message="firstPlaceIdsError(errors)" />

                        <div class="flex flex-wrap items-center gap-3">
                            <Button
                                type="submit"
                                :disabled="processing || selected.length === 0"
                            >
                                {{ processing ? 'Saving…' : 'Save my seat' }}
                            </Button>
                            <span class="text-sm text-text-muted">{{
                                announcement
                            }}</span>
                        </div>
                    </Form>

                    <section
                        class="flex flex-col gap-3"
                        aria-labelledby="plan-list-heading"
                    >
                        <h2 id="plan-list-heading" class="text-lg font-medium">
                            Choose from the list
                        </h2>
                        <PlanListView
                            :plan="activePlan"
                            :categories="props.categories"
                            :claim="props.claim"
                            :availability="availability"
                            :selected-ids="selected"
                            @toggle="onToggle"
                            @hint="onHint"
                        />
                    </section>
                </div>
            </template>
        </template>
    </div>
</template>

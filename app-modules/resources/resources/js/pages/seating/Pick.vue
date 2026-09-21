<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AdmissionSeatController from '@/actions/Konventix/Resources/Http/Controllers/AdmissionSeatController';
import SeatAvailabilityController from '@/actions/Konventix/Resources/Http/Controllers/SeatAvailabilityController';
import { show as showTicket } from '@/routes/admission/tickets';
import { show as showEvent } from '@/routes/event';
import { show as showRegistration } from '@/routes/registration/mine';
import type { Reason } from '../../components/plan/eligibility';
import {
    categoriesHaveFreePlace,
    hintFor,
    reconcileSelection,
    selectWithSwap,
} from '../../components/plan/eligibility';
import {
    ASSIGNED_BY_ORGANISER_MESSAGE,
    NO_FREE_PLACES_MESSAGE,
    placeLabel,
    selectionAnnouncement,
} from '../../components/plan/labels';
import PlanTabs from '../../components/plan/PlanTabs.vue';
import SeatPickPanel from '../../components/plan/SeatPickPanel.vue';
import { useAvailabilityPoll } from '../../components/plan/useAvailabilityPoll';

/**
 * Choose your seat (design §7.4). One tab per published plan; each plan's
 * panel (`SeatPickPanel.vue`) holds the SVG scene and — behind the
 * Accessibility toggle — the list view over the same selection, hints in
 * a live region, and a plain form post of `placeIds[]`
 * (`SeatPickForm.vue`) — the Action and the exclusion constraint decide,
 * the page only refuses what it can already see.
 *
 * Availability polls every 15 s (`useAvailabilityPoll.ts`); a selected
 * place somebody else took meanwhile leaves the selection with a spoken
 * hint, so the submit does not fail on something the person could not
 * know. The "nothing free" banner (`soldOut`, 2026-09-10 review fix) is
 * derived from that same polled map (`categoriesHaveFreePlace`), not a
 * flag computed once at render. `SeatSummarySlot.vue` has no poll of its
 * own and keeps reading the server's `SeatSummaryData.noFreePlaces`; both
 * render the same `NO_FREE_PLACES_MESSAGE` so the wording cannot drift.
 *
 * 2026-09-21 bugfix round: a click on another place at the cap swaps
 * (`selectWithSwap`, the oldest selection leaves) instead of refusing;
 * the list of places as buttons is off by default behind the panel's
 * Accessibility toggle. Task 17b of the same round: the heading names the
 * event (`claim.eventName`, projected from Event's contract events) and
 * links it to the landing page when a slug is known.
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
    /** Per-ticket mode (#292 §6.4): one admission, one place. */
    admissionId?: string | null;
    maxPlaces?: number | null;
};

const props = defineProps<Props>();

/**
 * Per-ticket mode (#292 §6.4): the same picker opened for one admission —
 * a single place, saved to the admission's own route, back to its ticket
 * page. Everything else — categories, plans, the poll — is the claim's and
 * unchanged. `maxPlaces` replaces `claim.quantity` as the selection cap.
 */
const perTicket = computed(() => Boolean(props.admissionId));
const maxPlaces = computed(() => props.maxPlaces ?? props.claim.quantity);
/** The claim as the scene and the list see it: the cap is this page's, not the group's. */
const pickerClaim = computed(() => ({
    ...props.claim,
    quantity: maxPlaces.value,
}));

/**
 * Item 1 of the 2026-09-10 fix wave (#278): an `assign`-mode claim is the
 * organiser's to place, not the registrant's — the picker itself must not
 * render, only the explanatory state below. The title, heading and
 * description follow the same branch (re-review fix, #278).
 */
const assignedByOrganiser = computed(
    () => props.claim.seatingMode === 'assign',
);
const pageTitle = computed(() =>
    assignedByOrganiser.value ? 'Your seat' : 'Choose your seat',
);
const pageDescription = computed(() =>
    assignedByOrganiser.value
        ? ASSIGNED_BY_ORGANISER_MESSAGE
        : maxPlaces.value === 1
          ? perTicket.value
              ? 'Pick the place this ticket sits on.'
              : 'Pick one place from the categories your registration includes.'
          : `Pick up to ${maxPlaces.value} places from the categories your registration includes.`,
);

const activePlanId = ref(props.plans[0]?.id ?? '');
const activePlan = computed(
    () => props.plans.find((plan) => plan.id === activePlanId.value) ?? null,
);

const { availability } = useAvailabilityPoll(
    props.availability.places,
    () =>
        props.admissionId
            ? AdmissionSeatController.availability.url({
                  admission: props.admissionId,
              })
            : SeatAvailabilityController.show.url(props.registrationId),
    !assignedByOrganiser.value,
);
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
        maxPlaces.value,
    ),
);

function onToggle(placeId: string): void {
    hint.value = null;
    selected.value = selectWithSwap(selected.value, placeId, maxPlaces.value);
}

function onHint(reason: Reason): void {
    hint.value = hintFor(reason);
}

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
    <Head :title="pageTitle" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 py-6">
        <div>
            <Link
                :href="
                    props.admissionId
                        ? showTicket(props.admissionId)
                        : showRegistration(props.registrationId)
                "
                class="mb-4 inline-flex items-center gap-2 text-sm text-text-muted hover:text-text"
            >
                <ArrowLeft class="size-4" />
                {{ perTicket ? 'Your ticket' : 'Your registration' }}
            </Link>

            <header class="mb-8 space-y-0.5">
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ pageTitle
                    }}<template v-if="props.claim.eventName">
                        —
                        <Link
                            v-if="props.claim.eventSlug"
                            :href="showEvent(props.claim.eventSlug).url"
                            class="underline-offset-4 hover:underline"
                        >
                            {{ props.claim.eventName }}
                        </Link>
                        <template v-else>{{ props.claim.eventName }}</template>
                    </template>
                </h1>
                <p class="text-sm text-text-muted">{{ pageDescription }}</p>
            </header>
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
                >
                    <SeatPickPanel
                        :plan="activePlan"
                        :categories="props.categories"
                        :claim="pickerClaim"
                        :availability="availability"
                        :selected="selected"
                        :announcement="announcement"
                        :hint="hint"
                        :registration-id="props.registrationId"
                        :admission-id="props.admissionId ?? null"
                        @toggle="onToggle"
                        @hint="onHint"
                    />
                </div>
            </template>
        </template>
    </div>
</template>

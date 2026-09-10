<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Armchair } from '@lucide/vue';
import SeatPickController from '@/actions/Konventix/Resources/Http/Controllers/SeatPickController';
import { NO_FREE_PLACES_MESSAGE } from './plan/labels';

/**
 * Resources' contribution to Registration's account page, registered by
 * `ResourcesServiceProvider::registerSlots()` on `registration.mine.show`:
 * the chosen places, or the way to choose them. `summary` is null for a
 * registration without a seat claim — the common case, not an error — and
 * then this renders nothing rather than a "Seat" heading over a ticket
 * that includes none.
 *
 * `summary.noFreePlaces` (2026-09-10 batch amendment) reuses
 * `NO_FREE_PLACES_MESSAGE`, the same constant `Pick.vue` shows in its own
 * banner — one string, so the two pages never word "nothing free" any
 * differently for the same claim. It only replaces the "choose your seat"
 * copy while the claim is still unplaced: once a place is held, a claim
 * that could no longer be re-picked still has its own seat, which is not
 * the sold-out situation this is about.
 *
 * The link is Wayfinder's `SeatPickController.show`, never a URL carried
 * in the props.
 */
type Summary = Konventix.Resources.Data.SeatSummaryData;

const props = defineProps<{
    summary: Summary | null;
}>();
</script>

<template>
    <section
        v-if="props.summary"
        class="flex flex-col gap-3"
        aria-labelledby="registration-seat"
    >
        <h2 id="registration-seat" class="text-lg font-medium">
            {{ props.summary.quantity === 1 ? 'Seat' : 'Seats' }}
        </h2>

        <div
            class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-border bg-surface-raised p-4"
        >
            <template v-if="props.summary.placeRefs.length > 0">
                <ul class="flex flex-wrap gap-2" aria-label="Your places">
                    <li
                        v-for="ref in props.summary.placeRefs"
                        :key="ref"
                        class="rounded-full bg-success/15 px-3 py-1 text-sm font-medium text-success"
                    >
                        {{ ref }}
                    </li>
                </ul>
                <span class="flex-1" />
                <Link
                    :href="
                        SeatPickController.show(props.summary.registrationId)
                    "
                    class="inline-flex min-h-11 items-center gap-2 rounded-md border border-border px-4 py-2 text-sm font-medium hover:bg-subtle"
                >
                    <Armchair class="size-4" />
                    Change your seat
                </Link>
            </template>

            <template v-else>
                <p class="text-sm text-text-muted" role="status">
                    {{
                        props.summary.claimState === 'unplaced' &&
                        props.summary.noFreePlaces
                            ? NO_FREE_PLACES_MESSAGE
                            : props.summary.claimState === 'unplaced'
                              ? 'No place chosen yet.'
                              : 'Your seat is no longer assigned.'
                    }}
                </p>
                <span class="flex-1" />
                <Link
                    :href="
                        SeatPickController.show(props.summary.registrationId)
                    "
                    class="inline-flex min-h-11 items-center gap-2 rounded-md bg-accent px-4 py-2 text-sm font-medium text-on-accent hover:bg-accent/90"
                >
                    <Armchair class="size-4" />
                    Choose your seat
                </Link>
            </template>
        </div>
    </section>
</template>

<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { Bookmark, BookmarkX } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { reserve, unreserve } from '@/routes/admin/plans';

/**
 * The operator's own reservation on one place (design §7.3,
 * `ReservePlaceForOperator` / `ReleaseOperatorReservation`, both behind
 * `BookOnBehalf`). A reservation needs the projected resource row, so the
 * buttons wait for a clean document: an unsaved place has no row yet.
 *
 * `occupancy.places` carries one entry per place (`PlanAdminController::
 * occupancyFor()`), so a free or blocked place still arrives with
 * `holderType: null` rather than being absent — `holder` reads that
 * directly instead of branching on `entry === null`, and stays correct if
 * a caller ever hands over a sparse map instead.
 */
type Entry = Konventix.Resources.Data.PlanOccupancyData['places'][string];

const props = defineProps<{
    planId: string;
    placeId: string;
    entry: Entry | null;
    canReserve: boolean;
    dirty: boolean;
}>();

const emit = defineEmits<{ changed: [] }>();

const holder = computed(() => props.entry?.holderType ?? null);

const error = ref<string | null>(null);
const http = useHttp<Record<string, never>, unknown>({});

function run(request: Promise<unknown>): void {
    error.value = null;
    request
        .then(() => emit('changed'))
        .catch(() => {
            error.value =
                'The reservation could not be changed. Reload the occupancy and try again.';
        });
}

function reservePlace(): void {
    run(http.post(reserve({ plan: props.planId, place: props.placeId }).url));
}

function releasePlace(): void {
    run(
        http.delete(
            unreserve({ plan: props.planId, place: props.placeId }).url,
        ),
    );
}
</script>

<template>
    <section
        class="flex flex-col gap-2 border-t border-border pt-3"
        aria-labelledby="inspector-reservation"
    >
        <h3
            id="inspector-reservation"
            class="text-xs font-semibold text-text-muted uppercase"
        >
            Occupancy
        </h3>

        <p v-if="holder === null" class="text-sm text-text-muted">Free.</p>
        <p v-else-if="holder === 'operator'" class="text-sm text-text">
            Reserved by the organisation.
        </p>
        <p v-else class="text-sm text-text">
            {{ entry?.state === 'held' ? 'Held' : 'Booked' }} by registration
            <span class="font-mono text-xs">{{ entry?.registrationId }}</span>
        </p>

        <template v-if="canReserve">
            <Button
                v-if="holder === null"
                type="button"
                variant="outline"
                size="sm"
                :disabled="dirty || http.processing"
                @click="reservePlace"
            >
                <Bookmark class="size-4" />
                Reserve for the organisation
            </Button>
            <Button
                v-else-if="holder === 'operator'"
                type="button"
                variant="outline"
                size="sm"
                :disabled="dirty || http.processing"
                @click="releasePlace"
            >
                <BookmarkX class="size-4" />
                Release reservation
            </Button>
            <p v-if="dirty" class="text-xs text-text-muted">
                Save the plan first — a reservation needs the saved place.
            </p>
        </template>

        <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>
    </section>
</template>

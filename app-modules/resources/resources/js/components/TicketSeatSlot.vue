<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Armchair, X } from '@lucide/vue';
import { ref } from 'vue';
import AdmissionSeatController from '@/actions/Konventix/Resources/Http/Controllers/AdmissionSeatController';
import { Button } from '@/components/ui/button';
import { assignedByOrganiserMessage, noFreePlacesMessage } from './plan/labels';

/**
 * Resources' contribution to Admission's ticket page (#292 §6.4),
 * registered by `ResourcesServiceProvider::registerSlots()` on
 * `admission.ticket.show`: the way into the per-ticket picker, and the way
 * out of a seat already chosen. `seat` is null when the ticket has no seat
 * claim or the viewer may not act on it, and then this renders nothing.
 *
 * An `assign` claim is the organiser's to place: the same message the
 * registration page shows, no button. `noFreePlaces` reuses the picker's
 * own wording so the two never disagree about "nothing free".
 */
type Seat = Konventix.Resources.Data.TicketSeatActionData;

const props = defineProps<{ seat: Seat | null }>();

const busy = ref(false);

function giveUp(): void {
    if (busy.value || !props.seat) {
        return;
    }

    busy.value = true;
    router.delete(
        AdmissionSeatController.destroy.url({
            admission: props.seat.admissionId,
        }),
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}
</script>

<template>
    <div v-if="props.seat" class="flex flex-wrap items-center gap-2">
        <p
            v-if="props.seat.seatingMode === 'assign'"
            role="status"
            class="text-sm text-text-muted"
        >
            {{ assignedByOrganiserMessage() }}
        </p>
        <template v-else>
            <Link
                :href="
                    AdmissionSeatController.show.url({
                        admission: props.seat.admissionId,
                    })
                "
                class="inline-flex min-h-11 items-center gap-2 rounded-md border border-border px-4 py-2 text-sm font-medium text-text hover:border-accent"
            >
                <Armchair class="size-4" aria-hidden="true" />
                {{
                    props.seat.placeLabel
                        ? $t('Change seat')
                        : $t('Choose a seat')
                }}
            </Link>
            <Button
                v-if="props.seat.placeLabel"
                type="button"
                variant="ghost"
                :disabled="busy"
                @click="giveUp"
            >
                <X class="size-4" aria-hidden="true" />
                {{ $t('Give up seat') }}
            </Button>
            <p
                v-else-if="props.seat.noFreePlaces"
                role="status"
                class="text-sm text-text-muted"
            >
                {{ noFreePlacesMessage() }}
            </p>
        </template>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * The save came back 422 with occupied places the document would delete,
 * recategorise or block (design §6.3). The only resolution in v1 is
 * `release`: the booking is released with reason `plan_conflict` and the
 * registrant's claim goes back to "please choose a seat". Resending
 * carries the same `expectedVersion` — a version that moved meanwhile
 * still answers 409.
 */
type Conflict = Konventix.Resources.Data.PlanConflictData;
type Resolution = Konventix.Resources.Data.PlanConflictResolutionData;

const props = defineProps<{
    open: boolean;
    conflicts: Conflict[];
    processing: boolean;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
    resolve: [resolutions: Resolution[]];
}>();

const reasons: Record<Conflict['reason'], string> = {
    deleted: 'deleted',
    category_changed: 'moved to another category',
    blocked: 'blocked',
};

const bookingCount = computed(() =>
    props.conflicts.reduce(
        (sum, conflict) => sum + conflict.bookings.length,
        0,
    ),
);

function releaseAll(): void {
    emit(
        'resolve',
        props.conflicts.map((conflict): Resolution => ({
            placeId: conflict.placeId,
            resolution: 'release',
        })),
    );
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="max-h-[85vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Occupied places affected</DialogTitle>
                <DialogDescription>
                    {{ conflicts.length }}
                    {{ conflicts.length === 1 ? 'place' : 'places' }} with
                    {{ bookingCount }} active
                    {{ bookingCount === 1 ? 'booking' : 'bookings' }} would
                    change. Releasing sends the registrants back to choosing a
                    seat; no mail is sent.
                </DialogDescription>
            </DialogHeader>

            <ul class="flex flex-col gap-2 text-sm">
                <li
                    v-for="conflict in conflicts"
                    :key="conflict.placeId"
                    class="rounded-md border border-border p-2"
                >
                    <div class="font-medium text-text">
                        {{ conflict.ref ?? conflict.label ?? conflict.placeId }}
                        <span class="font-normal text-text-muted"
                            >— {{ reasons[conflict.reason] }}</span
                        >
                    </div>
                    <ul class="mt-1 text-xs text-text-muted">
                        <li
                            v-for="booking in conflict.bookings"
                            :key="booking.bookingId"
                        >
                            {{
                                booking.holderType === 'operator'
                                    ? 'Organisation reservation'
                                    : 'Registration'
                            }}
                            <span
                                v-if="booking.registrationId"
                                class="font-mono"
                                >{{ booking.registrationId }}</span
                            >
                        </li>
                    </ul>
                </li>
            </ul>

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="processing"
                    @click="emit('update:open', false)"
                    >Keep the plan as saved</Button
                >
                <Button
                    type="button"
                    variant="destructive"
                    :disabled="processing"
                    @click="releaseAll"
                >
                    Release {{ bookingCount }}
                    {{ bookingCount === 1 ? 'booking' : 'bookings' }} and save
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

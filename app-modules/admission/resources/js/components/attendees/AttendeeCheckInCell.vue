<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import { formatDateTime } from '@/lib/datetime';
import { checkInSourceLabel } from '../../lib/labels';

type CheckIn = Konventix.Admission.Data.AttendeeCheckInStateData;

/**
 * The check-in column (attendee-list design §4.1): in, out or never on the
 * current event day, with the time in the reader's own zone and whether the
 * door or the console recorded it. When no event day covers now, the state
 * is the latest across every day and says "last known" (Task 4 review M2).
 */
const props = defineProps<{ checkIn: CheckIn }>();

const label = computed(() => {
    switch (props.checkIn.direction) {
        case 'in':
            return trans('In');
        case 'out':
            return trans('Out');
        default:
            return trans('Not yet');
    }
});

const badgeClass = computed(() => {
    switch (props.checkIn.direction) {
        case 'in':
            return 'bg-success/15 text-success';
        case 'out':
            return 'bg-subtle text-text';
        default:
            return 'bg-subtle text-text-muted';
    }
});

const sourceLabel = computed(() =>
    checkInSourceLabel(props.checkIn.source === 'console' ? 'console' : 'door'),
);
</script>

<template>
    <div class="flex flex-col gap-0.5">
        <span
            class="inline-flex w-fit rounded-full px-2 py-0.5 text-xs font-medium"
            :class="badgeClass"
        >
            {{ label }}
        </span>
        <span v-if="checkIn.direction !== null" class="text-xs text-text-muted">
            {{ formatDateTime(checkIn.at) }} · {{ sourceLabel }}
        </span>
        <span
            v-if="checkIn.lastKnown && checkIn.direction !== null"
            class="text-xs text-text-muted italic"
        >
            {{ $t('Last known — no event day today') }}
        </span>
    </div>
</template>

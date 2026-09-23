<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { show as showRegistration } from '@/routes/admin/registrations';
import { registrationStatusLabel } from '../../lib/labels';

type Row = Konventix.Admission.Data.AttendeeRowData;

/**
 * The registration column (attendee-list design §4.1, D-ATT-05): the short
 * id as a link to the existing registration page, and the registration's
 * status as Admission knows it whenever it is anything but confirmed. An
 * `unconfirmed` row comes from a backfill whose emitter predates the status
 * field and only said "not confirmed" — the label says exactly that. A crew
 * badge has no registration at all.
 */
const props = defineProps<{ row: Row }>();

const status = computed(() => {
    const value = props.row.registrationStatus;

    if (value === null || value === 'confirmed') {
        return null;
    }

    return registrationStatusLabel(value);
});
</script>

<template>
    <div v-if="row.registrationId !== null" class="flex flex-col">
        <Link
            :href="showRegistration(row.registrationId).url"
            class="font-mono text-xs underline-offset-4 hover:underline"
            :aria-label="
                $t('Registration :id', {
                    id: row.registrationShortId ?? '',
                })
            "
        >
            {{ row.registrationShortId }}
        </Link>
        <span v-if="status !== null" class="text-xs text-text-muted">
            {{ status }}
        </span>
    </div>
    <span v-else class="text-xs text-text-muted">{{
        $t('No registration (crew)')
    }}</span>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { edit as editUser } from '@/routes/admin/users';

type Row = Konventix.Admission.Data.AttendeeRowData;

/**
 * The holder column (attendee-list design §4.1, D-ATT-05): the holder's name
 * as a real link to their user page — a `<tr>` cannot take focus, so this is
 * the keyboard's way in (`.ai/rules/js.md`) — and `@username` beside it. A
 * ticket nobody holds yet reads "Unassigned" with the buyer, linked the same
 * way when the buyer is known.
 */
defineProps<{ row: Row }>();
</script>

<template>
    <div v-if="row.holderUserId !== null" class="flex flex-col">
        <Link
            :href="editUser(row.holderUserId).url"
            class="font-medium text-text underline-offset-4 hover:underline"
        >
            {{ row.holderName ?? 'Unnamed holder' }}
        </Link>
        <span v-if="row.holderUsername" class="text-xs text-text-muted">
            @{{ row.holderUsername }}
        </span>
    </div>
    <div v-else class="flex flex-col">
        <span class="font-medium text-text-muted">Unassigned</span>
        <span class="text-xs text-text-muted">
            No holder ·
            <template v-if="row.buyerUserId !== null">
                bought by
                <Link
                    :href="editUser(row.buyerUserId).url"
                    class="underline-offset-4 hover:underline"
                >
                    {{ row.buyerName ?? 'an unnamed buyer' }}
                </Link>
            </template>
            <template v-else>no buyer on record</template>
        </span>
    </div>
</template>

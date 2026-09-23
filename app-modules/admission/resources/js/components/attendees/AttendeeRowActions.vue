<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { MoreHorizontal } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { show as showTicket } from '@/routes/admin/tickets';
import CheckInDialog from './CheckInDialog.vue';
import CheckOutDialog from './CheckOutDialog.vue';

type Row = Konventix.Admission.Data.AttendeeRowData;

/**
 * A row's action menu (attendee-list design §4.3, D-ATT-03): Check in,
 * Check out, Show ticket. The two writes open their dialogs; their enabled
 * state is the row's own `canCheckIn` / `canCheckOut`, and the server
 * decides regardless.
 */
defineProps<{ row: Row }>();

const checkingIn = ref(false);
const checkingOut = ref(false);
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon-sm"
                :aria-label="`Actions for ticket ${row.reference}`"
            >
                <MoreHorizontal class="size-4" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuItem
                :disabled="!row.canCheckIn"
                @select="checkingIn = true"
            >
                Check in
            </DropdownMenuItem>
            <DropdownMenuItem
                :disabled="!row.canCheckOut"
                @select="checkingOut = true"
            >
                Check out
            </DropdownMenuItem>
            <DropdownMenuItem as-child>
                <Link :href="showTicket(row.admissionId).url">Show ticket</Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>

    <CheckInDialog v-model:open="checkingIn" :ticket="row" />
    <CheckOutDialog v-model:open="checkingOut" :ticket="row" />
</template>

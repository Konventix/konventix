<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';
import { index as attendeesIndex } from '@/routes/admin/events/attendees';
import { show as showRegistration } from '@/routes/admin/registrations';
import { edit as editUser } from '@/routes/admin/users';
import CheckInDialog from '../../../components/attendees/CheckInDialog.vue';
import CheckOutDialog from '../../../components/attendees/CheckOutDialog.vue';
import TicketHistory from '../../../components/attendees/TicketHistory.vue';

type Ticket = Konventix.Admission.Data.TicketDetailData;

/**
 * One ticket in the console (attendee-list design §4.4, D-ATT-05, #329):
 * reference, state, validity, holder and buyer (each linking to the user
 * page), type, the door checks with their answers, the full check-in
 * history, the registration as Admission knows it, the existing badge link,
 * and the same Check in / Check out actions as the attendee list.
 */
const props = defineProps<{ ticket: Ticket }>();

const checkingIn = ref(false);
const checkingOut = ref(false);

const stateLabels: Record<string, string> = {
    issued: 'Issued',
    assigned: 'Assigned',
    revoked: 'Revoked',
    expired: 'Expired',
};

const registrationStatusLabels: Record<string, string> = {
    confirmed: 'Confirmed',
    unconfirmed: 'Not confirmed',
    submitted: 'Submitted',
    approved: 'Approved',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

const validity = computed(() => {
    const { validFrom, validUntil } = props.ticket;

    if (validFrom === null && validUntil === null) {
        return 'Every event day';
    }

    return `${validFrom === null ? 'from the start' : formatDateTime(validFrom)} – ${validUntil === null ? 'the end' : formatDateTime(validUntil)}`;
});

const outcomeLabels: Record<string, string> = {
    confirmed: 'Confirmed',
    refused: 'Refused',
};
</script>

<template>
    <Head :title="`Ticket ${ticket.reference}`" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="`Ticket ${ticket.reference}`"
            :description="`${ticket.eventName} · ${ticket.ticketType} · ${stateLabels[ticket.state] ?? ticket.state}`"
        >
            <Button as-child variant="outline" size="sm">
                <Link :href="attendeesIndex(ticket.eventId).url">
                    <ArrowLeft class="size-4" />
                    Attendees
                </Link>
            </Button>
            <Button
                v-if="ticket.badgeUrl !== null"
                as-child
                variant="outline"
                size="sm"
            >
                <a :href="ticket.badgeUrl" target="_blank" rel="noopener">
                    Print badge
                </a>
            </Button>
            <Button
                size="sm"
                :disabled="!ticket.canCheckIn"
                @click="checkingIn = true"
            >
                Check in
            </Button>
            <Button
                size="sm"
                variant="secondary"
                :disabled="!ticket.canCheckOut"
                @click="checkingOut = true"
            >
                Check out
            </Button>
        </AdminPageHeader>

        <section class="grid gap-4 md:grid-cols-2">
            <dl
                class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 rounded-lg border border-border p-4 text-sm"
            >
                <dt class="text-text-muted">State</dt>
                <dd>
                    {{ stateLabels[ticket.state] ?? ticket.state }}
                    <span v-if="ticket.canCheckOut" class="text-success">
                        · in today
                    </span>
                </dd>
                <dt class="text-text-muted">Valid</dt>
                <dd>{{ validity }}</dd>
                <dt class="text-text-muted">Holder</dt>
                <dd>
                    <Link
                        v-if="ticket.holderUserId !== null"
                        :href="editUser(ticket.holderUserId).url"
                        class="underline-offset-4 hover:underline"
                    >
                        {{ ticket.holderName ?? 'Unnamed holder' }}
                    </Link>
                    <span v-else class="text-text-muted">Unassigned</span>
                    <span v-if="ticket.holderUsername" class="text-text-muted">
                        @{{ ticket.holderUsername }}
                    </span>
                </dd>
                <dt class="text-text-muted">Buyer</dt>
                <dd>
                    <Link
                        v-if="ticket.buyerUserId !== null"
                        :href="editUser(ticket.buyerUserId).url"
                        class="underline-offset-4 hover:underline"
                    >
                        {{ ticket.buyerName ?? 'Unnamed buyer' }}
                    </Link>
                    <span v-else class="text-text-muted"
                        >No buyer on record</span
                    >
                    <span v-if="ticket.buyerUsername" class="text-text-muted">
                        @{{ ticket.buyerUsername }}
                    </span>
                </dd>
            </dl>

            <dl
                class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 rounded-lg border border-border p-4 text-sm"
            >
                <template v-if="ticket.registrationId !== null">
                    <dt class="text-text-muted">Registration</dt>
                    <dd>
                        <Link
                            :href="showRegistration(ticket.registrationId).url"
                            class="font-mono underline-offset-4 hover:underline"
                            :aria-label="`Registration ${ticket.registrationShortId}`"
                        >
                            {{ ticket.registrationShortId }}
                        </Link>
                    </dd>
                    <dt class="text-text-muted">Type</dt>
                    <dd>
                        {{ ticket.registrationTypeName ?? 'Not known yet' }}
                    </dd>
                    <dt class="text-text-muted">Status</dt>
                    <dd>
                        {{
                            ticket.registrationStatus === null
                                ? 'Not known yet'
                                : (registrationStatusLabels[
                                      ticket.registrationStatus
                                  ] ?? ticket.registrationStatus)
                        }}
                    </dd>
                </template>
                <template v-else>
                    <dt class="text-text-muted">Registration</dt>
                    <dd class="text-text-muted">
                        None — a crew ticket ({{ ticket.role }})
                    </dd>
                </template>
            </dl>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="text-base font-semibold">Door checks</h2>
            <p
                v-if="ticket.doorChecks.length === 0"
                class="text-sm text-text-muted"
            >
                This ticket carries no entry conditions.
            </p>
            <ul v-else class="grid gap-2 text-sm">
                <li
                    v-for="check in ticket.doorChecks"
                    :key="check.key"
                    class="flex flex-wrap items-baseline gap-x-2 rounded-md border border-border p-3"
                >
                    <span class="font-medium">{{ check.label }}</span>
                    <span v-if="check.documentHint" class="text-text-muted">
                        {{ check.documentHint }}
                    </span>
                    <span
                        class="ml-auto"
                        :class="
                            check.outcome === 'refused'
                                ? 'text-danger'
                                : check.outcome === 'confirmed'
                                  ? 'text-success'
                                  : 'text-text-muted'
                        "
                    >
                        {{
                            check.outcome === null
                                ? check.verification === 'document_at_door'
                                    ? 'Not answered yet'
                                    : 'Not checked at the door'
                                : outcomeLabels[check.outcome]
                        }}
                    </span>
                </li>
            </ul>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="text-base font-semibold">Check-in history</h2>
            <TicketHistory :history="ticket.history" />
        </section>
    </div>

    <CheckInDialog v-model:open="checkingIn" :ticket="ticket" />
    <CheckOutDialog v-model:open="checkingOut" :ticket="ticket" />
</template>

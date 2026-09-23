<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
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
import {
    admissionStateLabel,
    doorCheckOutcomeLabel,
    registrationStatusLabel,
} from '../../../lib/labels';

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

const validity = computed(() => {
    const { validFrom, validUntil } = props.ticket;

    if (validFrom === null && validUntil === null) {
        return trans('Every event day');
    }

    const from =
        validFrom === null
            ? trans('from the start')
            : formatDateTime(validFrom);
    const until =
        validUntil === null ? trans('the end') : formatDateTime(validUntil);

    return `${from} – ${until}`;
});
</script>

<template>
    <Head :title="$t('Ticket :reference', { reference: ticket.reference })" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="$t('Ticket :reference', { reference: ticket.reference })"
            :description="`${ticket.eventName} · ${ticket.ticketType} · ${admissionStateLabel(ticket.state)}`"
        >
            <Button as-child variant="outline" size="sm">
                <Link :href="attendeesIndex(ticket.eventId).url">
                    <ArrowLeft class="size-4" />
                    {{ $t('Attendees') }}
                </Link>
            </Button>
            <Button
                v-if="ticket.badgeUrl !== null"
                as-child
                variant="outline"
                size="sm"
            >
                <a :href="ticket.badgeUrl" target="_blank" rel="noopener">
                    {{ $t('Print badge') }}
                </a>
            </Button>
            <Button
                size="sm"
                :disabled="!ticket.canCheckIn"
                @click="checkingIn = true"
            >
                {{ $t('Check in') }}
            </Button>
            <Button
                size="sm"
                variant="secondary"
                :disabled="!ticket.canCheckOut"
                @click="checkingOut = true"
            >
                {{ $t('Check out') }}
            </Button>
        </AdminPageHeader>

        <section class="grid gap-4 md:grid-cols-2">
            <dl
                class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 rounded-lg border border-border p-4 text-sm"
            >
                <dt class="text-text-muted">{{ $t('State') }}</dt>
                <dd>
                    {{ admissionStateLabel(ticket.state) }}
                    <span v-if="ticket.canCheckOut" class="text-success">
                        · {{ $t('in today') }}
                    </span>
                </dd>
                <dt class="text-text-muted">{{ $t('Valid') }}</dt>
                <dd>{{ validity }}</dd>
                <dt class="text-text-muted">{{ $t('Holder') }}</dt>
                <dd>
                    <Link
                        v-if="ticket.holderUserId !== null"
                        :href="editUser(ticket.holderUserId).url"
                        class="underline-offset-4 hover:underline"
                    >
                        {{ ticket.holderName ?? $t('Unnamed holder') }}
                    </Link>
                    <span v-else class="text-text-muted">{{
                        $t('Unassigned')
                    }}</span>
                    <span v-if="ticket.holderUsername" class="text-text-muted">
                        @{{ ticket.holderUsername }}
                    </span>
                </dd>
                <dt class="text-text-muted">{{ $t('Buyer') }}</dt>
                <dd>
                    <Link
                        v-if="ticket.buyerUserId !== null"
                        :href="editUser(ticket.buyerUserId).url"
                        class="underline-offset-4 hover:underline"
                    >
                        {{ ticket.buyerName ?? $t('Unnamed buyer') }}
                    </Link>
                    <span v-else class="text-text-muted">{{
                        $t('No buyer on record')
                    }}</span>
                    <span v-if="ticket.buyerUsername" class="text-text-muted">
                        @{{ ticket.buyerUsername }}
                    </span>
                </dd>
            </dl>

            <dl
                class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 rounded-lg border border-border p-4 text-sm"
            >
                <template v-if="ticket.registrationId !== null">
                    <dt class="text-text-muted">{{ $t('Registration') }}</dt>
                    <dd>
                        <Link
                            :href="showRegistration(ticket.registrationId).url"
                            class="font-mono underline-offset-4 hover:underline"
                            :aria-label="
                                $t('Registration :id', {
                                    id: ticket.registrationShortId ?? '',
                                })
                            "
                        >
                            {{ ticket.registrationShortId }}
                        </Link>
                    </dd>
                    <dt class="text-text-muted">{{ $t('Type') }}</dt>
                    <dd>
                        {{ ticket.registrationTypeName ?? $t('Not known yet') }}
                    </dd>
                    <dt class="text-text-muted">{{ $t('Status') }}</dt>
                    <dd>
                        {{
                            ticket.registrationStatus === null
                                ? $t('Not known yet')
                                : registrationStatusLabel(
                                      ticket.registrationStatus,
                                  )
                        }}
                    </dd>
                </template>
                <template v-else>
                    <dt class="text-text-muted">{{ $t('Registration') }}</dt>
                    <dd class="text-text-muted">
                        {{
                            $t('None — a crew ticket (:role)', {
                                role: ticket.role,
                            })
                        }}
                    </dd>
                </template>
            </dl>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="text-base font-semibold">{{ $t('Door checks') }}</h2>
            <p
                v-if="ticket.doorChecks.length === 0"
                class="text-sm text-text-muted"
            >
                {{ $t('This ticket carries no entry conditions.') }}
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
                                    ? $t('Not answered yet')
                                    : $t('Not checked at the door')
                                : doorCheckOutcomeLabel(check.outcome)
                        }}
                    </span>
                </li>
            </ul>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="text-base font-semibold">
                {{ $t('Check-in history') }}
            </h2>
            <TicketHistory :history="ticket.history" />
        </section>
    </div>

    <CheckInDialog v-model:open="checkingIn" :ticket="ticket" />
    <CheckOutDialog v-model:open="checkingOut" :ticket="ticket" />
</template>

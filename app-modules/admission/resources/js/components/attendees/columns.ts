import { Link } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { h } from 'vue';
import { show as showTicket } from '@/routes/admin/tickets';
import AttendeeCheckInCell from './AttendeeCheckInCell.vue';
import AttendeeHolderCell from './AttendeeHolderCell.vue';
import AttendeeRegistrationCell from './AttendeeRegistrationCell.vue';
import AttendeeRowActions from './AttendeeRowActions.vue';

export type AttendeeRow = Konventix.Admission.Data.AttendeeRowData;

const stateLabels: Record<string, string> = {
    issued: 'Issued',
    assigned: 'Assigned',
    revoked: 'Revoked',
    expired: 'Expired',
};

const stateClasses: Record<string, string> = {
    issued: 'bg-subtle text-text',
    assigned: 'bg-success/15 text-success',
    revoked: 'bg-danger/15 text-danger',
    expired: 'bg-subtle text-text-muted',
};

/**
 * The attendee list's columns (attendee-list design §4.1). The two sortable
 * ids, `name` and `activity`, must stay in sync with
 * `AttendeeListQuery::SORTS`; the table renders their headers as buttons
 * with `aria-sort` (`DataTable.vue`).
 */
export const attendeeColumns: ColumnDef<AttendeeRow>[] = [
    {
        id: 'name',
        header: 'Holder',
        enableSorting: true,
        cell: ({ row }) => h(AttendeeHolderCell, { row: row.original }),
    },
    {
        id: 'email',
        header: 'Email',
        enableSorting: false,
        cell: ({ row }) =>
            h('span', { class: 'text-text-muted' }, row.original.email ?? '—'),
    },
    {
        id: 'ticketType',
        header: 'Ticket type',
        enableSorting: false,
        cell: ({ row }) => h('span', {}, row.original.ticketType),
    },
    {
        id: 'reference',
        header: 'Ticket',
        enableSorting: false,
        // The reference a guest reads out at the desk (design §3), as a
        // real link to the ticket page (D-ATT-05) — the keyboard's way in,
        // since a `<tr>` cannot take focus (`.ai/rules/js.md`).
        cell: ({ row }) =>
            h(
                Link,
                {
                    href: showTicket(row.original.admissionId).url,
                    class: 'font-mono text-xs underline-offset-4 hover:underline',
                    'aria-label': `Ticket ${row.original.reference}`,
                },
                () => row.original.reference,
            ),
    },
    {
        id: 'registration',
        header: 'Registration',
        enableSorting: false,
        cell: ({ row }) => h(AttendeeRegistrationCell, { row: row.original }),
    },
    {
        id: 'state',
        header: 'State',
        enableSorting: false,
        cell: ({ row }) =>
            h(
                'span',
                {
                    class: `inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${stateClasses[row.original.state] ?? 'bg-subtle text-text-muted'}`,
                },
                stateLabels[row.original.state] ?? row.original.state,
            ),
    },
    {
        id: 'activity',
        header: 'Check-in',
        enableSorting: true,
        cell: ({ row }) =>
            h(AttendeeCheckInCell, { checkIn: row.original.checkIn }),
    },
    {
        id: 'actions',
        header: () => h('span', { class: 'sr-only' }, 'Actions'),
        enableSorting: false,
        // Check in, Check out, Show ticket (design §4.3, D-ATT-03).
        cell: ({ row }) => h(AttendeeRowActions, { row: row.original }),
    },
];

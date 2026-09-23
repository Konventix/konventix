import type { ColumnDef } from '@tanstack/vue-table';
import { h } from 'vue';
import AttendeeCheckInCell from './AttendeeCheckInCell.vue';
import AttendeeHolderCell from './AttendeeHolderCell.vue';
import AttendeeRegistrationCell from './AttendeeRegistrationCell.vue';

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
        // The reference a guest reads out at the desk (design §3). It
        // becomes the link to the ticket page once that page exists
        // (Task 5).
        cell: ({ row }) =>
            h('span', { class: 'font-mono text-xs' }, row.original.reference),
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
];

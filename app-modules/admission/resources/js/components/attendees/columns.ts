import { Link } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { trans } from 'laravel-vue-i18n';
import { h } from 'vue';
import { show as showTicket } from '@/routes/admin/tickets';
import { admissionStateLabel } from '../../lib/labels';
import AttendeeCheckInCell from './AttendeeCheckInCell.vue';
import AttendeeHolderCell from './AttendeeHolderCell.vue';
import AttendeeRegistrationCell from './AttendeeRegistrationCell.vue';
import AttendeeRowActions from './AttendeeRowActions.vue';

export type AttendeeRow = Konventix.Admission.Data.AttendeeRowData;

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
 *
 * A function, not a constant: the headers are looked up in the catalog,
 * which is loaded after this module is imported (ADR-0068).
 */
export function attendeeColumns(): ColumnDef<AttendeeRow>[] {
    return [
        {
            id: 'name',
            header: trans('Holder'),
            enableSorting: true,
            cell: ({ row }) => h(AttendeeHolderCell, { row: row.original }),
        },
        {
            id: 'email',
            header: trans('Email'),
            enableSorting: false,
            cell: ({ row }) =>
                h(
                    'span',
                    { class: 'text-text-muted' },
                    row.original.email ?? '—',
                ),
        },
        {
            id: 'ticketType',
            header: trans('Ticket type'),
            enableSorting: false,
            cell: ({ row }) => h('span', {}, row.original.ticketType),
        },
        {
            id: 'reference',
            header: trans('Ticket'),
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
                        'aria-label': trans('Ticket :reference', {
                            reference: row.original.reference,
                        }),
                    },
                    () => row.original.reference,
                ),
        },
        {
            id: 'registration',
            header: trans('Registration'),
            enableSorting: false,
            cell: ({ row }) =>
                h(AttendeeRegistrationCell, { row: row.original }),
        },
        {
            id: 'state',
            header: trans('State'),
            enableSorting: false,
            cell: ({ row }) =>
                h(
                    'span',
                    {
                        class: `inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${stateClasses[row.original.state] ?? 'bg-subtle text-text-muted'}`,
                    },
                    admissionStateLabel(row.original.state),
                ),
        },
        {
            id: 'activity',
            header: trans('Check-in'),
            enableSorting: true,
            cell: ({ row }) =>
                h(AttendeeCheckInCell, { checkIn: row.original.checkIn }),
        },
        {
            id: 'actions',
            header: () => h('span', { class: 'sr-only' }, trans('Actions')),
            enableSorting: false,
            // Check in, Check out, Show ticket (design §4.3, D-ATT-03).
            cell: ({ row }) => h(AttendeeRowActions, { row: row.original }),
        },
    ];
}

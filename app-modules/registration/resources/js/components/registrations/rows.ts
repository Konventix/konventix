import { Link } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { h } from 'vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';
import { show as showRegistration } from '@/routes/admin/registrations';

/**
 * One row of the operator console's registration list, and the columns that
 * render it.
 *
 * Extracted from `pages/admin/registrations/Index.vue` when D-REG-15's
 * reduce dialog was added: the page was already at the ~300-line limit
 * CLAUDE_extra sets, and a table whose cells are `h()` calls is the part of
 * it that is not markup at all. The page keeps the state and the handlers;
 * this file turns them into columns.
 */
export type RegistrationRow = {
    id: string;
    eventId: string;
    registrant: string;
    registrantEmail: string | null;
    organization: string | null;
    status: string;
    paymentStatus: string;
    submittedAt: string;
    approvable: boolean;
    /** How many units of the offer this act buys (Task 19). */
    quantity: number;
    /** Confirmed and above one unit — the only rows a reduction applies to. */
    reducible: boolean;
};

const statusClasses: Record<string, string> = {
    submitted: 'bg-warning/15 text-warning',
    approved: 'bg-accent/15 text-accent',
    confirmed: 'bg-success/15 text-success',
    rejected: 'bg-danger/15 text-danger',
    cancelled: 'bg-subtle text-text-muted',
};

/**
 * `isSelected`/`onToggle` drive the bulk-approval checkbox; `canReduce` is
 * the capability, so a reader without it never gets the button at all
 * (the route authorizes again regardless). `onReduce` opens the page's
 * dialog for that row.
 */
export function registrationColumns(options: {
    isSelected: (id: string) => boolean;
    onToggle: (id: string) => void;
    canReduce: boolean;
    onReduce: (row: RegistrationRow) => void;
}): ColumnDef<RegistrationRow>[] {
    return [
        {
            id: 'select',
            header: '',
            enableSorting: false,
            cell: ({ row }) =>
                row.original.approvable
                    ? h('input', {
                          type: 'checkbox',
                          'aria-label': `Select ${row.original.registrant}`,
                          checked: options.isSelected(row.original.id),
                          onChange: () => options.onToggle(row.original.id),
                      })
                    : null,
        },
        {
            id: 'registrant',
            header: 'Registrant',
            enableSorting: false,
            // A real link, not a row click: the detail has to be reachable
            // by keyboard (WCAG 2.1.1, .ai/rules/js.md).
            cell: ({ row }) =>
                h('div', { class: 'flex flex-col' }, [
                    h(
                        Link,
                        {
                            href: showRegistration(row.original.id).url,
                            class: 'font-medium hover:text-accent',
                        },
                        () => row.original.registrant,
                    ),
                    row.original.registrantEmail
                        ? h(
                              'span',
                              { class: 'text-xs text-text-muted' },
                              row.original.registrantEmail,
                          )
                        : null,
                ]),
        },
        {
            id: 'organization',
            header: 'Organization',
            enableSorting: false,
            cell: ({ row }) =>
                h(
                    'span',
                    { class: 'text-text-muted' },
                    row.original.organization ?? '—',
                ),
        },
        {
            accessorKey: 'status',
            header: 'Status',
            enableSorting: true,
            cell: ({ row }) => {
                const status = row.getValue<string>('status');

                return h(
                    'span',
                    {
                        class: `inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${statusClasses[status] ?? 'bg-subtle text-text-muted'}`,
                    },
                    status,
                );
            },
        },
        {
            accessorKey: 'payment_status',
            accessorFn: (row) => row.paymentStatus,
            header: 'Payment',
            enableSorting: true,
            cell: ({ row }) =>
                h(
                    'span',
                    { class: 'text-text-muted' },
                    row.original.paymentStatus,
                ),
        },
        {
            id: 'quantity',
            header: 'Units',
            enableSorting: false,
            cell: ({ row }) =>
                h(
                    'span',
                    { class: 'tabular-nums' },
                    String(row.original.quantity),
                ),
        },
        {
            accessorKey: 'submitted_at',
            accessorFn: (row) => row.submittedAt,
            header: 'Submitted',
            enableSorting: true,
            // The instant carries its offset; the reader's own zone is what
            // the column shows, with the machine-readable value on the
            // `<time>`.
            cell: ({ row }) =>
                h(
                    'time',
                    {
                        class: 'text-text-muted',
                        datetime: row.original.submittedAt,
                    },
                    formatDateTime(row.original.submittedAt),
                ),
        },
        {
            id: 'actions',
            header: '',
            enableSorting: false,
            cell: ({ row }) =>
                options.canReduce && row.original.reducible
                    ? h(
                          Button,
                          {
                              type: 'button',
                              variant: 'outline',
                              size: 'sm',
                              onClick: () => options.onReduce(row.original),
                          },
                          () => 'Reduce',
                      )
                    : null,
        },
    ];
}

import type { ColumnDef } from '@tanstack/vue-table';
import { h } from 'vue';

export type OrderRow = {
    id: string;
    settlesType: string;
    settlesId: string;
    status: string;
    currency: string;
    totalNet: string;
    totalGross: string;
    taxTreatment: string;
    paidAt: string | null;
    createdAt: string | null;
    invoice: {
        id: string;
        number: string;
        dueOn: string;
        documentReady: boolean;
    } | null;
};

const statusClasses: Record<string, string> = {
    pending: 'bg-warning/15 text-warning',
    paid: 'bg-success/15 text-success',
    cancelled: 'bg-danger/15 text-danger',
};

const treatmentLabels: Record<string, string> = {
    standard: 'USt.',
    reverse_charge: 'Reverse charge',
    small_business: '§ 19 UStG',
};

/**
 * Sort-eligible columns must stay in sync with the allowlist in
 * `OrderAdminController::index()` (`created_at`, `status`, `total_gross`).
 */
export const columns: ColumnDef<OrderRow>[] = [
    {
        id: 'invoice',
        header: 'Invoice',
        enableSorting: false,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'font-medium' },
                row.original.invoice?.number ?? '—',
            ),
    },
    {
        accessorKey: 'settlesId',
        header: 'Settles',
        enableSorting: false,
        cell: ({ row }) =>
            h('span', { class: 'text-text-muted' }, [
                h('span', {}, `${row.original.settlesType} `),
                h(
                    'span',
                    { class: 'font-mono text-xs' },
                    row.original.settlesId,
                ),
            ]),
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
        accessorKey: 'total_gross',
        accessorFn: (row) => row.totalGross,
        header: 'Total',
        enableSorting: true,
        cell: ({ row }) =>
            h('span', { class: 'tabular-nums' }, [
                h(
                    'span',
                    {},
                    `${row.original.totalGross} ${row.original.currency}`,
                ),
                h(
                    'span',
                    { class: 'ml-1 text-xs text-text-muted' },
                    treatmentLabels[row.original.taxTreatment] ?? '',
                ),
            ]),
    },
    {
        id: 'dueOn',
        header: 'Due',
        enableSorting: false,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                row.original.paidAt !== null
                    ? `paid ${row.original.paidAt}`
                    : (row.original.invoice?.dueOn ?? '—'),
            ),
    },
    {
        accessorKey: 'created_at',
        accessorFn: (row) => row.createdAt,
        header: 'Placed',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                row.original.createdAt ?? '—',
            ),
    },
];

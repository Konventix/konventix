import { Link } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import type { VNode } from 'vue';
import { h } from 'vue';
import { show as showOrder } from '@/routes/admin/orders';
import type { OrderReversal, UnappliedPayment } from './reversals';
import {
    orderStatusLabel,
    repaymentDeadline,
    reversalKindLabel,
} from './reversals';

export type OrderRow = {
    id: string;
    settlesType: string;
    settlesId: string;
    status: string;
    currency: string;
    totalNet: string;
    totalGross: string;
    taxTreatment: string;
    provider: string | null;
    reversals: OrderReversal[];
    unappliedPayment: UnappliedPayment | null;
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
    payment_reversed: 'bg-danger/15 text-danger',
};

const reversalStatusClasses: Record<string, string> = {
    open: 'bg-warning/15 text-warning',
    final: 'bg-danger/15 text-danger',
    withdrawn: 'bg-success/15 text-success',
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
        // A link, not a span: the table's row click opens this same page,
        // and a `<tr>` cannot take focus — without a real link here the
        // order is unreachable by keyboard (WCAG 2.1.1). An order without an
        // invoice yet still needs the way in, so the number falls back to the
        // label the row would otherwise show nothing for.
        cell: ({ row }) =>
            h(
                Link,
                {
                    href: showOrder(row.original.id).url,
                    class: 'font-medium underline-offset-4 hover:underline',
                },
                () => row.original.invoice?.number ?? 'Open order',
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
            const deadline = repaymentDeadline(row.original.reversals);

            const badge = h(
                'span',
                {
                    class: `inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${statusClasses[status] ?? 'bg-subtle text-text-muted'}`,
                },
                orderStatusLabel(status),
            );

            if (status !== 'payment_reversed' || deadline === null) {
                return badge;
            }

            // The sale stands and the amount is owed again (ADR-0057
            // point 3); the status word alone does not say until when.
            return h('span', { class: 'flex flex-wrap items-baseline gap-1' }, [
                badge,
                h(
                    'span',
                    { class: 'text-xs text-text-muted' },
                    `— owed again until ${deadline}`,
                ),
            ]);
        },
    },
    {
        accessorKey: 'provider',
        header: 'Provider',
        enableSorting: false,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                row.original.provider ?? '—',
            ),
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

/**
 * The reversals view's own column (ADR-0057 point 8): what the provider
 * took back and why, and money that arrived for an order that cannot take
 * it. Only that view renders it — on the ordinary list every cell would be
 * empty, and the status column already carries the reversed orders.
 */
export const reversalColumn: ColumnDef<OrderRow> = {
    id: 'reversal',
    header: 'Reversal',
    enableSorting: false,
    cell: ({ row }) => {
        const entries: VNode[] = row.original.reversals.map((reversal) =>
            h('div', { class: 'flex flex-col gap-0.5' }, [
                h('div', { class: 'flex flex-wrap items-baseline gap-2' }, [
                    h(
                        'span',
                        {
                            class: `inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${reversalStatusClasses[reversal.status] ?? 'bg-subtle text-text-muted'}`,
                        },
                        `${reversalKindLabel(reversal.kind)} · ${reversal.status}`,
                    ),
                    h(
                        'span',
                        { class: 'tabular-nums' },
                        `${reversal.amount} ${reversal.currency}`,
                    ),
                ]),
                h(
                    'span',
                    { class: 'font-mono text-xs text-text-muted' },
                    reversal.providerReference,
                ),
                reversal.reasonCode
                    ? h(
                          'span',
                          { class: 'text-xs text-text-muted' },
                          reversal.reasonCode,
                      )
                    : null,
                reversal.repayDueOn
                    ? h(
                          'span',
                          { class: 'text-xs text-text-muted' },
                          `owed again until ${reversal.repayDueOn}`,
                      )
                    : null,
            ]),
        );

        const unapplied = row.original.unappliedPayment;

        if (unapplied) {
            entries.push(
                h('div', { class: 'flex flex-col gap-0.5' }, [
                    h('div', { class: 'flex flex-wrap items-baseline gap-2' }, [
                        h(
                            'span',
                            {
                                class: 'inline-flex rounded-full bg-warning/15 px-2 py-0.5 text-xs font-medium text-warning',
                            },
                            'Unapplied payment',
                        ),
                        h(
                            'span',
                            { class: 'tabular-nums' },
                            `${unapplied.amount} ${unapplied.currency}`,
                        ),
                    ]),
                    h(
                        'span',
                        { class: 'text-xs text-text-muted' },
                        `${unapplied.provider} · received ${unapplied.receivedAt ?? 'unknown'}`,
                    ),
                ]),
            );
        }

        if (entries.length === 0) {
            return h('span', { class: 'text-text-muted' }, '—');
        }

        return h('div', { class: 'flex flex-col gap-2' }, entries);
    },
};

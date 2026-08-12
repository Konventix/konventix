import type { ColumnDef } from '@tanstack/vue-table';
import { h } from 'vue';
import { Badge } from '@/components/ui/badge';

export type UserRow = {
    id: string;
    name: string;
    email: string;
    state: string;
    verified: boolean;
    brandCount: number;
};

/**
 * Sort/search-eligible columns must stay in sync with the allowlist in
 * `UserAdminController::index()` (`name`, `email`).
 */
export const columns: ColumnDef<UserRow>[] = [
    {
        accessorKey: 'name',
        header: 'Name',
        enableSorting: true,
        cell: ({ row }) =>
            h('span', { class: 'font-medium' }, row.getValue<string>('name')),
    },
    {
        accessorKey: 'email',
        header: 'Email',
        enableSorting: true,
        cell: ({ row }) =>
            h('div', { class: 'flex items-center gap-1.5' }, [
                h(
                    'span',
                    { class: 'text-text-muted' },
                    row.getValue<string>('email'),
                ),
                row.original.verified
                    ? null
                    : h(
                          Badge,
                          {
                              variant: 'outline',
                              class: 'border-warning/40 text-warning',
                          },
                          () => 'unverified',
                      ),
            ]),
    },
    {
        id: 'state',
        accessorFn: (row) => row.state,
        header: 'State',
        enableSorting: false,
        cell: ({ row }) => {
            const state = row.getValue<string>('state');

            return h(
                Badge,
                {
                    variant: 'outline',
                    class:
                        state === 'active'
                            ? 'border-success/40 text-success'
                            : 'border-danger/40 text-danger',
                },
                () => state,
            );
        },
    },
    {
        id: 'brandCount',
        accessorFn: (row) => row.brandCount,
        header: 'Brands',
        enableSorting: false,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                String(row.getValue<number>('brandCount')),
            ),
    },
];

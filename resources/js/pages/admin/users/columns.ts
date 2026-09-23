import { Link } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { trans } from 'laravel-vue-i18n';
import { h } from 'vue';
import { Badge } from '@/components/ui/badge';
import { edit as editUser } from '@/routes/admin/users';

export type UserRow = {
    id: string;
    name: string;
    email: string;
    state: string;
    verified: boolean;
    brandCount: number;
};

/**
 * The account state (`Identity\Enums\AccountState`) as a reader sees it;
 * the raw value stays the fallback for a state added later.
 */
export function accountStateLabel(state: string): string {
    const labels: Record<string, string> = {
        active: trans('Active'),
        locked: trans('Account locked'),
        anonymized: trans('Anonymized'),
        merged: trans('Merged'),
    };

    return labels[state] ?? state;
}

/**
 * Sort/search-eligible columns must stay in sync with the allowlist in
 * `UserAdminController::index()` (`name`, `email`).
 *
 * A function, not a constant: the headers are catalog lookups, and the
 * catalog is loaded after this module is imported (ADR-0068).
 */
export function userColumns(): ColumnDef<UserRow>[] {
    return [
        {
            accessorKey: 'name',
            header: trans('Name'),
            enableSorting: true,
            // A link, not a span: the table's row click opens this same page,
            // and a `<tr>` cannot take focus — without a real link here the
            // account is unreachable by keyboard (WCAG 2.1.1).
            cell: ({ row }) =>
                h(
                    Link,
                    {
                        href: editUser(row.original.id).url,
                        class: 'font-medium underline-offset-4 hover:underline',
                    },
                    () => row.getValue<string>('name'),
                ),
        },
        {
            accessorKey: 'email',
            header: trans('Email'),
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
                              () => trans('unverified'),
                          ),
                ]),
        },
        {
            id: 'state',
            accessorFn: (row) => row.state,
            header: trans('State'),
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
                    () => accountStateLabel(state),
                );
            },
        },
        {
            id: 'brandCount',
            accessorFn: (row) => row.brandCount,
            header: trans('Brands'),
            enableSorting: false,
            cell: ({ row }) =>
                h(
                    'span',
                    { class: 'text-text-muted' },
                    String(row.getValue<number>('brandCount')),
                ),
        },
    ];
}

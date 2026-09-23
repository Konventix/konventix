import { Link } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { trans } from 'laravel-vue-i18n';
import { h } from 'vue';
import { edit as editBrand } from '@/routes/admin/brands';
import { edit as editBrandTheme } from '@/routes/admin/brands/theme';

export type BrandRow = {
    id: string;
    name: string;
    slug: string;
    hosts: string[];
};

/**
 * Sort/search-eligible columns must stay in sync with the allowlist in
 * `BrandAdminController::index()` (`name`, `slug`).
 *
 * A function, not a constant: the headers are catalog lookups, and the
 * catalog is loaded after this module is imported (ADR-0068).
 */
export function brandColumns(): ColumnDef<BrandRow>[] {
    return [
        {
            accessorKey: 'name',
            header: trans('Name'),
            enableSorting: true,
            // A link, not a span: the table's row click opens this same page,
            // and a `<tr>` cannot take focus — without a real link here the
            // brand is unreachable by keyboard (WCAG 2.1.1).
            cell: ({ row }) =>
                h(
                    Link,
                    {
                        href: editBrand(row.original.id).url,
                        class: 'font-medium underline-offset-4 hover:underline',
                    },
                    () => row.getValue<string>('name'),
                ),
        },
        {
            accessorKey: 'slug',
            header: trans('Slug'),
            enableSorting: true,
            cell: ({ row }) =>
                h(
                    'span',
                    { class: 'text-text-muted' },
                    row.getValue<string>('slug'),
                ),
        },
        {
            id: 'hosts',
            header: trans('Hosts'),
            enableSorting: false,
            cell: ({ row }) =>
                h(
                    'span',
                    { class: 'text-text-muted' },
                    row.original.hosts.join(', ') || '—',
                ),
        },
        {
            id: 'actions',
            header: '',
            enableSorting: false,
            cell: ({ row }) =>
                h(
                    Link,
                    {
                        href: editBrandTheme(row.original.id).url,
                        class: 'text-sm text-text-muted hover:text-text',
                    },
                    () => trans('Theme'),
                ),
        },
    ];
}

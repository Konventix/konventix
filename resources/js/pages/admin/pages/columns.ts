import { Link } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { trans } from 'laravel-vue-i18n';
import { h } from 'vue';
import { formatDateTime } from '@/lib/datetime';
import { edit as editPage } from '@/routes/admin/pages';

export type PageRow = Konventix.Platform.Data.StaticPageRowData;

/**
 * Sort-eligible columns must stay in sync with the allowlist in
 * `StaticPageAdminController::index()` (`title`, `slug`, `published_at`,
 * `updated_at`).
 *
 * A function, not a constant: the headers are catalog lookups, and the
 * catalog is loaded after this module is imported (ADR-0068).
 */
export function pageColumns(): ColumnDef<PageRow>[] {
    return [
        {
            accessorKey: 'title',
            header: trans('Title'),
            enableSorting: true,
            // A link, not a span: the table's row click opens this same page,
            // and a `<tr>` cannot take focus — without a real link here the
            // page is unreachable by keyboard (WCAG 2.1.1).
            cell: ({ row }) =>
                h(
                    Link,
                    {
                        href: editPage(row.original.id).url,
                        class: 'font-medium underline-offset-4 hover:underline',
                    },
                    () => row.getValue<string>('title'),
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
            id: 'brand',
            header: trans('Brand'),
            enableSorting: false,
            cell: ({ row }) =>
                h('span', { class: 'text-text-muted' }, row.original.brandName),
        },
        {
            accessorKey: 'published_at',
            header: trans('Status'),
            enableSorting: true,
            cell: ({ row }) =>
                h(
                    'span',
                    {
                        class: row.original.published
                            ? 'inline-flex rounded-full bg-success/15 px-2 py-0.5 text-xs font-medium text-success'
                            : 'inline-flex rounded-full bg-subtle px-2 py-0.5 text-xs font-medium text-text-muted',
                    },
                    row.original.published
                        ? trans('Published')
                        : trans('Draft'),
                ),
        },
        {
            accessorKey: 'updated_at',
            header: trans('Updated'),
            enableSorting: true,
            cell: ({ row }) =>
                h(
                    'span',
                    { class: 'text-text-muted' },
                    formatDateTime(row.original.updatedAt) || '—',
                ),
        },
    ];
}

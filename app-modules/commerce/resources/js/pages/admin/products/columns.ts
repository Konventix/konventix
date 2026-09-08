import { Link } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { h } from 'vue';
import { formatDateTime } from '@/lib/datetime';
import { edit as editProduct } from '@/routes/admin/products';

export type ProductRow = Konventix.Commerce.Data.ProductRowData;

/**
 * Sort-eligible columns must stay in sync with the allowlist in
 * `ProductAdminController::index()` (`name`, `published_at`, `updated_at`).
 */
export const columns: ColumnDef<ProductRow>[] = [
    {
        accessorKey: 'name',
        header: 'Name',
        enableSorting: true,
        // A link, not a span: the table's row click opens this same page,
        // and a `<tr>` cannot take focus — without a real link here the
        // product is unreachable by keyboard (WCAG 2.1.1).
        cell: ({ row }) =>
            h(
                Link,
                {
                    href: editProduct(row.original.id).url,
                    class: 'font-medium underline-offset-4 hover:underline',
                },
                () => row.getValue<string>('name'),
            ),
    },
    {
        accessorKey: 'slug',
        header: 'Slug',
        enableSorting: false,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                row.getValue<string>('slug'),
            ),
    },
    {
        id: 'brand',
        header: 'Brand',
        enableSorting: false,
        cell: ({ row }) =>
            h('span', { class: 'text-text-muted' }, row.original.brandName),
    },
    {
        id: 'price',
        header: 'Price',
        enableSorting: false,
        cell: ({ row }) =>
            h('span', { class: 'tabular-nums' }, [
                h('span', {}, row.original.unitAmountNet),
                h(
                    'span',
                    { class: 'ml-1 text-xs text-text-muted' },
                    row.original.priceDisplay,
                ),
            ]),
    },
    {
        accessorKey: 'published_at',
        header: 'Status',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                'span',
                {
                    class: row.original.published
                        ? 'inline-flex rounded-full bg-success/15 px-2 py-0.5 text-xs font-medium text-success'
                        : 'inline-flex rounded-full bg-subtle px-2 py-0.5 text-xs font-medium text-text-muted',
                },
                row.original.published ? 'Published' : 'Draft',
            ),
    },
    {
        accessorKey: 'updated_at',
        header: 'Updated',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                formatDateTime(row.original.updatedAt) || '—',
            ),
    },
];

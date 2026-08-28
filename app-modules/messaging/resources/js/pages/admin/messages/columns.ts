import { Link } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { h } from 'vue';
import { show as showMessage } from '@/routes/admin/messages';

export type MessageRow = Konventix.Messaging.Data.MessageLogRowData;

/**
 * Status pill tone — mirrors the "Published/Draft" idiom from the products
 * list, one arm per `MessageStatus` case. `queued`/`held` share the same
 * "still on its way" tone; `suppressed` reads as muted rather than as an
 * error, since it is a deliberate refusal (preferences or the suppression
 * list), not a failure.
 */
const statusClasses: Record<string, string> = {
    sent: 'bg-success/15 text-success',
    failed: 'bg-danger/15 text-danger',
    queued: 'bg-warning/15 text-warning',
    held: 'bg-warning/15 text-warning',
    suppressed: 'bg-subtle text-text-muted',
};

/**
 * Sort-eligible columns must stay in sync with the allowlist in
 * `MessageLogAdminController::index()` (`queued_at`, `sent_at`, `failed_at`,
 * `status`).
 */
export const columns: ColumnDef<MessageRow>[] = [
    {
        accessorKey: 'recipientEmail',
        header: 'Recipient',
        enableSorting: false,
        // A link, not a span: the table's row click opens this same page,
        // and a `<tr>` cannot take focus — without a real link here the
        // message is unreachable by keyboard (WCAG 2.1.1).
        cell: ({ row }) =>
            h(
                Link,
                {
                    href: showMessage(row.original.id).url,
                    class: 'font-medium underline-offset-4 hover:underline',
                },
                () => row.getValue<string>('recipientEmail'),
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
        accessorKey: 'templateKey',
        header: 'Template',
        enableSorting: false,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'font-mono text-xs text-text-muted' },
                row.getValue<string>('templateKey'),
            ),
    },
    {
        accessorKey: 'channel',
        header: 'Channel',
        enableSorting: false,
        cell: ({ row }) =>
            h('span', { class: 'text-text-muted' }, row.original.channel),
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
        accessorKey: 'queuedAt',
        header: 'Queued',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                row.original.queuedAt ?? '—',
            ),
    },
    {
        accessorKey: 'sentAt',
        header: 'Sent',
        enableSorting: true,
        cell: ({ row }) =>
            h('span', { class: 'text-text-muted' }, row.original.sentAt ?? '—'),
    },
    {
        id: 'lastError',
        header: 'Last error',
        enableSorting: false,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-xs text-danger' },
                row.original.lastError ?? '',
            ),
    },
];

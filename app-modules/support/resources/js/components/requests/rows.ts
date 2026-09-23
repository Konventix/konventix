import { Link } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { trans } from 'laravel-vue-i18n';
import { h } from 'vue';
import { formatDateTime } from '@/lib/datetime';
import { show as showSupportRequest } from '@/routes/admin/support/requests';
import {
    supportCategorySummary,
    supportStateClass,
    supportStateLabel,
} from './supportConsole';

/**
 * One line of the console inbox and the columns that render it (design
 * §9.1), extracted from the page the way Registration's list is: a table
 * whose cells are `h()` calls is the part of an index page that is not
 * markup at all, and the page stays well under the size guideline without
 * it.
 *
 * The labels and colours live in `supportConsole.ts`, which imports
 * nothing — this file cannot be unit-tested because of the Wayfinder import
 * above (`.ai/rules/js.md`), and that is exactly why the decisions worth
 * testing are next door rather than here.
 */
export type SupportRequestRow = Konventix.Support.Data.SupportInboxRowData;

export function supportRequestColumns(): ColumnDef<SupportRequestRow>[] {
    return [
        {
            id: 'reference',
            header: trans('Reference'),
            enableSorting: false,
            // A real link, not a row click: the detail has to be reachable
            // by keyboard (WCAG 2.1.1, `.ai/rules/js.md`).
            cell: ({ row }) =>
                h('div', { class: 'flex items-center gap-2' }, [
                    h(
                        Link,
                        {
                            href: showSupportRequest(row.original.id).url,
                            class: 'font-medium tabular-nums hover:text-accent',
                        },
                        () => row.original.reference,
                    ),
                    row.original.isKnownIssue
                        ? h(
                              'span',
                              {
                                  class: 'rounded-full bg-warning/15 px-2 py-0.5 text-xs font-medium text-warning',
                                  title: trans('Marked as a known issue'),
                              },
                              trans('Known'),
                          )
                        : null,
                ]),
        },
        {
            id: 'categories',
            header: trans('Categories'),
            enableSorting: false,
            cell: ({ row }) =>
                h(
                    'span',
                    { class: 'text-text-muted' },
                    supportCategorySummary(row.original.categories),
                ),
        },
        {
            accessorKey: 'state',
            header: trans('State'),
            enableSorting: true,
            cell: ({ row }) =>
                h(
                    'span',
                    {
                        class: `inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${supportStateClass(row.original.state)}`,
                    },
                    supportStateLabel(row.original.state),
                ),
        },
        {
            id: 'brand',
            header: trans('Brand'),
            enableSorting: false,
            // "Console" for a report filed on the operator host, which has
            // no brand at all (ADR-0063) — resolved on the server so this
            // cell never has to know the rule.
            cell: ({ row }) => h('span', {}, row.original.brandLabel),
        },
        {
            id: 'release',
            header: trans('Release'),
            enableSorting: false,
            cell: ({ row }) =>
                h(
                    'span',
                    { class: 'text-text-muted tabular-nums' },
                    row.original.release ?? '—',
                ),
        },
        {
            id: 'reporter',
            header: trans('Reporter'),
            enableSorting: false,
            // Masked, always. The unmasked address is behind
            // `support.view-reporter-contact` on the detail page and every
            // view of it is audited (§10.8) — a list cannot honestly do
            // that a hundred times per glance.
            cell: ({ row }) =>
                h(
                    'span',
                    { class: 'text-text-muted' },
                    row.original.reporterLabel,
                ),
        },
        {
            accessorKey: 'created_at',
            accessorFn: (row) => row.createdAt,
            header: trans('Received'),
            enableSorting: true,
            cell: ({ row }) =>
                h(
                    'time',
                    {
                        class: 'text-text-muted',
                        datetime: row.original.createdAt ?? undefined,
                    },
                    formatDateTime(row.original.createdAt),
                ),
        },
        {
            id: 'assignee',
            header: trans('Assignee'),
            enableSorting: false,
            // A ULID, because the account's display name lives in Identity
            // and this module may not read it (ADR-0004) — the same reason
            // Platform's role editor prints them.
            cell: ({ row }) =>
                h(
                    'span',
                    { class: 'font-mono text-xs text-text-muted' },
                    row.original.assignedToUserId ?? trans('Unassigned'),
                ),
        },
    ];
}

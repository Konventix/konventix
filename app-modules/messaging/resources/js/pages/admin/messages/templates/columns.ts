import { Link } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { trans } from 'laravel-vue-i18n';
import { h } from 'vue';
import { Badge } from '@/components/ui/badge';
import { edit as editTemplate } from '@/routes/admin/messages/templates';

export type TemplateRow = Konventix.Messaging.Data.TemplateSummaryData;

/**
 * Sort-eligible columns must stay in sync with the allowlist in
 * `TemplateAdminController::index()` (`key`, `module`).
 *
 * A function, not a constant: the headers are catalog lookups, and the
 * catalog is loaded after this module is imported (ADR-0068).
 */
export function templateColumns(): ColumnDef<TemplateRow>[] {
    return [
        {
            accessorKey: 'key',
            header: trans('Key'),
            enableSorting: true,
            // A link, not a span: the table's row click opens this same page,
            // and a `<tr>` cannot take focus — without a real link here the
            // template is unreachable by keyboard (WCAG 2.1.1).
            cell: ({ row }) =>
                h(
                    Link,
                    {
                        href: editTemplate(row.original.key).url,
                        class: 'font-mono text-xs underline-offset-4 hover:underline',
                    },
                    () => row.getValue<string>('key'),
                ),
        },
        {
            accessorKey: 'module',
            header: trans('Module'),
            enableSorting: true,
            cell: ({ row }) =>
                h('span', { class: 'text-text-muted' }, row.original.module),
        },
        {
            id: 'locales',
            header: trans('Locales'),
            enableSorting: false,
            cell: ({ row }) =>
                h(
                    'span',
                    { class: 'flex flex-wrap gap-1' },
                    row.original.locales.map((locale) =>
                        h(
                            Badge,
                            {
                                key: locale,
                                variant:
                                    row.original.overriddenLocales.includes(
                                        locale,
                                    )
                                        ? 'default'
                                        : 'secondary',
                            },
                            () =>
                                row.original.overriddenLocales.includes(locale)
                                    ? trans(':locale · overridden', { locale })
                                    : locale,
                        ),
                    ),
                ),
        },
    ];
}

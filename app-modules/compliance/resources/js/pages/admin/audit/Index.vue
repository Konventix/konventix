<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import { computed, h } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import DataTable from '@/components/admin/DataTable.vue';
import DataTableToolbar from '@/components/admin/DataTableToolbar.vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { DataTableFilters } from '@/composables/useDataTable';
import { useDataTable } from '@/composables/useDataTable';
import { formatDateTime } from '@/lib/datetime';
import { index as auditIndex } from '@/routes/admin/audit';

/**
 * One page over one table, filtered — LanCore had ten per-entity `Audit.vue`
 * screens. The trail is a single record of privileged action, not a feature
 * each model grows its own copy of.
 *
 * Read-only: there is no write endpoint to call.
 */
type AuditRow = {
    id: string;
    action: string;
    actorUserId: string | null;
    subjectId: string | null;
    brandName: string | null;
    context: Record<string, unknown>;
    occurredAt: string;
};

interface PaginatedEntries {
    data: AuditRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    entries: PaginatedEntries;
    actions: string[];
    filters: DataTableFilters;
}>();

const { filters, setSearch, toggleSort, setPerPage, setPage, navigate } =
    useDataTable(() => auditIndex().url, props.filters);

const sorting = computed<SortingState>(() =>
    filters.sort ? [{ id: filters.sort, desc: filters.dir === 'desc' }] : [],
);

function onSortingChange(next: SortingState): void {
    if (next.length > 0) {
        toggleSort(next[0].id);
    }
}

/** 'all' is the absent filter — Select has no empty-value option. */
function setActionFilter(value: string): void {
    filters.action = value === 'all' ? undefined : value;
    navigate({ page: undefined });
}

const pagination = computed(() => ({
    currentPage: props.entries.current_page,
    lastPage: props.entries.last_page,
    total: props.entries.total,
    from: props.entries.from,
    to: props.entries.to,
}));

function summarizeContext(context: Record<string, unknown>): string {
    return Object.entries(context)
        .filter(([, value]) => value !== null && value !== '')
        .map(([key, value]) => `${key}: ${String(value)}`)
        .join(' · ');
}

const columns: ColumnDef<AuditRow>[] = [
    {
        accessorKey: 'occurred_at',
        header: 'When',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'whitespace-nowrap' },
                formatDateTime(row.original.occurredAt),
            ),
    },
    {
        accessorKey: 'action',
        header: 'Action',
        enableSorting: true,
        cell: ({ row }) =>
            h('div', { class: 'flex flex-col' }, [
                h('span', { class: 'font-medium' }, row.original.action),
                h(
                    'span',
                    { class: 'text-xs text-text-muted' },
                    summarizeContext(row.original.context),
                ),
            ]),
    },
    {
        id: 'actor',
        header: 'Actor',
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-xs text-text-muted' },
                row.original.actorUserId ?? 'system',
            ),
    },
    {
        id: 'subject',
        header: 'Subject',
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-xs text-text-muted' },
                row.original.subjectId ?? '—',
            ),
    },
    {
        id: 'brand',
        header: 'Brand',
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                row.original.brandName ?? 'operator',
            ),
    },
];
</script>

<template>
    <Head title="Audit trail" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Audit trail"
            description="Privileged actions across every brand — appended, never edited"
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            search-placeholder="Search action, actor or subject…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
        >
            <template #actions>
                <Select
                    :model-value="(filters.action as string) ?? 'all'"
                    @update:model-value="
                        (value) => setActionFilter(String(value))
                    "
                >
                    <SelectTrigger class="w-64" aria-label="Filter by action">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All actions</SelectItem>
                        <SelectItem
                            v-for="action in props.actions"
                            :key="action"
                            :value="action"
                        >
                            {{ action }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </template>
        </DataTableToolbar>

        <DataTable
            data-test="audit-table"
            :columns="columns"
            :data="props.entries.data"
            :sorting="sorting"
            :pagination="pagination"
            items-label="entries"
            empty-message="Nothing recorded yet."
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

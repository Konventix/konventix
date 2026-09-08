<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
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
import { formatCalendarDate } from '@/lib/datetime';
import {
    index as requestsIndex,
    show as showRequest,
} from '@/routes/admin/subject-requests';

/**
 * The statutory queue. Sorted by deadline by default and showing days left
 * rather than a date, because the only question this screen answers is
 * "what runs out first".
 */
type RequestRow = {
    id: string;
    subjectId: string;
    brandName: string;
    type: Konventix.Compliance.Enums.DataSubjectRequestType;
    state: Konventix.Compliance.Enums.DataSubjectRequestState;
    deadlineAt: string;
    daysLeft: number;
    openModules: number;
};

interface PaginatedRequests {
    data: RequestRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    requests: PaginatedRequests;
    states: Konventix.Compliance.Enums.DataSubjectRequestState[];
    types: Konventix.Compliance.Enums.DataSubjectRequestType[];
    filters: DataTableFilters;
}>();

const { filters, setSearch, toggleSort, setPerPage, setPage, navigate } =
    useDataTable(() => requestsIndex().url, props.filters);

const sorting = computed<SortingState>(() =>
    filters.sort ? [{ id: filters.sort, desc: filters.dir === 'desc' }] : [],
);

function onSortingChange(next: SortingState): void {
    if (next.length > 0) {
        toggleSort(next[0].id);
    }
}

/** 'all' is the absent filter — Select has no empty-value option. */
function setFilter(key: 'state' | 'type', value: string): void {
    filters[key] = value === 'all' ? undefined : value;
    navigate({ page: undefined });
}

const pagination = computed(() => ({
    currentPage: props.requests.current_page,
    lastPage: props.requests.last_page,
    total: props.requests.total,
    from: props.requests.from,
    to: props.requests.to,
}));

const stateClasses: Record<
    Konventix.Compliance.Enums.DataSubjectRequestState,
    string
> = {
    received: 'bg-accent/15 text-accent',
    in_progress: 'bg-warning/15 text-warning',
    fulfilled: 'bg-success/15 text-success',
    refused: 'bg-subtle text-text-muted',
};

const columns: ColumnDef<RequestRow>[] = [
    {
        accessorKey: 'type',
        header: 'Type',
        enableSorting: true,
        cell: ({ row }) =>
            h('div', { class: 'flex flex-col' }, [
                h(
                    Link,
                    {
                        href: showRequest(row.original.id).url,
                        class: 'font-medium hover:text-accent',
                    },
                    () => row.original.type,
                ),
                h(
                    'span',
                    { class: 'text-xs text-text-muted' },
                    row.original.subjectId,
                ),
            ]),
    },
    {
        accessorKey: 'state',
        header: 'State',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                'span',
                {
                    class: `inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${stateClasses[row.original.state]}`,
                },
                row.original.state,
            ),
    },
    {
        id: 'brand',
        header: 'Brand',
        cell: ({ row }) =>
            h('span', { class: 'text-text-muted' }, row.original.brandName),
    },
    {
        accessorKey: 'deadline_at',
        header: 'Deadline',
        enableSorting: true,
        cell: ({ row }) =>
            h('div', { class: 'flex flex-col' }, [
                h('span', {}, formatCalendarDate(row.original.deadlineAt)),
                h(
                    'span',
                    {
                        class:
                            row.original.daysLeft < 5
                                ? 'text-xs text-danger'
                                : 'text-xs text-text-muted',
                    },
                    row.original.daysLeft < 0
                        ? `${Math.abs(row.original.daysLeft)} days overdue`
                        : `${row.original.daysLeft} days left`,
                ),
            ]),
    },
    {
        id: 'modules',
        header: 'Modules open',
        cell: ({ row }) =>
            h(
                'span',
                {
                    class:
                        row.original.openModules > 0
                            ? 'text-warning'
                            : 'text-text-muted',
                },
                String(row.original.openModules),
            ),
    },
];
</script>

<template>
    <Head title="Data requests" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Data requests"
            description="Export, deletion and rectification requests, with their statutory deadlines"
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            search-placeholder="Search subject id or email…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
        >
            <template #actions>
                <Select
                    :model-value="(filters.state as string) ?? 'all'"
                    @update:model-value="
                        (value) => setFilter('state', String(value))
                    "
                >
                    <SelectTrigger class="w-40" aria-label="Filter by state">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All states</SelectItem>
                        <SelectItem
                            v-for="state in props.states"
                            :key="state"
                            :value="state"
                        >
                            {{ state }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <Select
                    :model-value="(filters.type as string) ?? 'all'"
                    @update:model-value="
                        (value) => setFilter('type', String(value))
                    "
                >
                    <SelectTrigger class="w-36" aria-label="Filter by type">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All types</SelectItem>
                        <SelectItem
                            v-for="type in props.types"
                            :key="type"
                            :value="type"
                        >
                            {{ type }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </template>
        </DataTableToolbar>

        <DataTable
            data-test="subject-requests-table"
            :columns="columns"
            :data="props.requests.data"
            :sorting="sorting"
            :pagination="pagination"
            items-label="requests"
            empty-message="No data requests."
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

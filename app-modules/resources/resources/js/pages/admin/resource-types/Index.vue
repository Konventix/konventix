<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import { trans } from 'laravel-vue-i18n';
import { computed, h } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import DataTable from '@/components/admin/DataTable.vue';
import DataTableToolbar from '@/components/admin/DataTableToolbar.vue';
import type {
    DataTableFilterDef,
    DataTableFilters,
} from '@/composables/useDataTable';
import { useDataTable } from '@/composables/useDataTable';
import {
    edit as editType,
    index as typesIndex,
} from '@/routes/admin/resource-types';
import ResourceTypeCreateDialog from '../../../components/admin/ResourceTypeCreateDialog.vue';
import { capacityModeLabel } from '../../../components/plan/labels';

/**
 * Categories of an event (design §8.1): what a place is, what a seating
 * grant may choose from, and how the picker draws it — a colour token and a
 * symbol, because state is never conveyed by colour alone (BFSG).
 *
 * The "Bookable places" column is deliberately not a raw row count
 * (`ResourceTypeRowData`, #282): it is what the operator reads off this
 * screen to size the category's Registration quota capacity, so it counts
 * only published, unblocked places.
 *
 * The event filter is a toolbar filter declared once on the backend and
 * once here (.ai/rules/pages.md); the options are this module's own event
 * projection (ADR-0004).
 */
type TypeRow = Konventix.Resources.Data.ResourceTypeRowData;

interface PaginatedTypes {
    data: TypeRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    types: PaginatedTypes;
    brands: { id: string; name: string }[];
    events: { id: string; name: string; brandId: string }[];
    options: { mode: string[]; token: string[] };
    filters: DataTableFilters & { event: string | null };
}>();

const { filters, setSearch, setFilter, toggleSort, setPerPage, setPage } =
    useDataTable(() => typesIndex().url, props.filters);

const filterDefs = computed<DataTableFilterDef[]>(() => [
    {
        name: 'event',
        label: trans('Events'),
        value: props.filters.event,
        options: props.events.map((event) => ({
            value: event.id,
            label: event.name,
        })),
    },
]);

const sorting = computed<SortingState>(() =>
    filters.sort ? [{ id: filters.sort, desc: filters.dir === 'desc' }] : [],
);

function onSortingChange(next: SortingState): void {
    if (next.length > 0) {
        toggleSort(next[0].id);
    }
}

const pagination = computed(() => ({
    currentPage: props.types.current_page,
    lastPage: props.types.last_page,
    total: props.types.total,
    from: props.types.from,
    to: props.types.to,
}));

const columns: ColumnDef<TypeRow>[] = [
    {
        accessorKey: 'name',
        header: trans('Name'),
        enableSorting: true,
        cell: ({ row }) =>
            h(
                Link,
                {
                    href: editType(row.original.id).url,
                    class: 'font-medium hover:text-accent',
                },
                () => row.original.name,
            ),
    },
    {
        id: 'event',
        header: trans('Event'),
        cell: ({ row }) =>
            h('span', { class: 'text-text-muted' }, row.original.eventName),
    },
    {
        id: 'token',
        header: trans('Token'),
        cell: ({ row }) =>
            h('span', { class: 'inline-flex items-center gap-2' }, [
                h('span', {
                    class: 'size-3 rounded-full border border-border',
                    style: { backgroundColor: `var(--${row.original.token})` },
                    'aria-hidden': 'true',
                }),
                h('span', { class: 'text-text-muted' }, row.original.token),
            ]),
    },
    {
        id: 'symbol',
        header: trans('Symbol'),
        cell: ({ row }) =>
            h('span', { class: 'text-text-muted' }, row.original.symbol ?? '—'),
    },
    {
        accessorKey: 'sort_order',
        header: trans('Sort order'),
        enableSorting: true,
        cell: ({ row }) => String(row.original.sortOrder),
    },
    {
        id: 'mode',
        header: trans('Mode'),
        cell: ({ row }) =>
            h(
                'span',
                {
                    class: 'rounded-full bg-subtle px-2 py-0.5 text-xs text-text-muted',
                },
                capacityModeLabel(row.original.mode),
            ),
    },
    {
        id: 'places',
        // Bookable, not raw — see the module docblock above.
        header: trans('Bookable places'),
        cell: ({ row }) => String(row.original.places),
    },
];
</script>

<template>
    <Head :title="$t('Categories')" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="$t('Categories')"
            :description="
                $t(
                    'What a place is: the categories a seating grant chooses from and the picker draws',
                )
            "
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            :filters="filterDefs"
            :search-placeholder="$t('Search categories…')"
            @update:search="setSearch"
            @update:per-page="setPerPage"
            @update:filter="setFilter"
        >
            <template #actions>
                <ResourceTypeCreateDialog
                    :brands="props.brands"
                    :events="props.events"
                    :options="props.options"
                />
            </template>
        </DataTableToolbar>

        <DataTable
            data-test="resource-types-table"
            :columns="columns"
            :data="props.types.data"
            :sorting="sorting"
            :pagination="pagination"
            :items-label="$t('categories')"
            :empty-message="
                $t('No categories yet — create one per kind of place.')
            "
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

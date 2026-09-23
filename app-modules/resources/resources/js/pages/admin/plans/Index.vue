<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import { trans } from 'laravel-vue-i18n';
import { computed, h } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import DataTable from '@/components/admin/DataTable.vue';
import DataTableToolbar from '@/components/admin/DataTableToolbar.vue';
import { Button } from '@/components/ui/button';
import type {
    DataTableFilterDef,
    DataTableFilters,
} from '@/composables/useDataTable';
import { useDataTable } from '@/composables/useDataTable';
import { formatDateTime } from '@/lib/datetime';
import {
    download as downloadPlan,
    edit as editPlan,
    index as plansIndex,
    publish as publishPlan,
    unpublish as unpublishPlan,
} from '@/routes/admin/plans';
import PlanCreateDialog from '../../../components/admin/PlanCreateDialog.vue';
import PlanImportDialog from '../../../components/admin/PlanImportDialog.vue';

/**
 * Hall plans of the operator's events (design §8.2): name, event, places,
 * version, published — with the create dialog, the import upload, the
 * export link and the publish toggle. The Edit link opens the plan editor
 * (§8.3); a plan can also be built by import.
 *
 * The export is a plain anchor, not an Inertia link: it is a file, and an
 * Inertia visit would fetch it over XHR and show the JSON in the error
 * modal.
 */
type PlanRow = Konventix.Resources.Data.PlanRowData;

interface PaginatedPlans {
    data: PlanRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    plans: PaginatedPlans;
    brands: { id: string; name: string }[];
    events: { id: string; name: string; brandId: string }[];
    filters: DataTableFilters & { event: string | null };
}>();

const { filters, setSearch, setFilter, toggleSort, setPerPage, setPage } =
    useDataTable(() => plansIndex().url, props.filters);

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
    currentPage: props.plans.current_page,
    lastPage: props.plans.last_page,
    total: props.plans.total,
    from: props.plans.from,
    to: props.plans.to,
}));

function togglePublish(row: PlanRow): void {
    const route = row.publishedAt === null ? publishPlan : unpublishPlan;

    router.post(route(row.id).url, {}, { preserveScroll: true });
}

const columns: ColumnDef<PlanRow>[] = [
    {
        accessorKey: 'name',
        header: trans('Name'),
        enableSorting: true,
        cell: ({ row }) =>
            h('div', { class: 'flex flex-col' }, [
                h('span', { class: 'font-medium' }, row.original.name),
                row.original.floorLabel
                    ? h(
                          'span',
                          { class: 'text-xs text-text-muted' },
                          row.original.floorLabel,
                      )
                    : null,
            ]),
    },
    {
        id: 'event',
        header: trans('Event'),
        cell: ({ row }) =>
            h('span', { class: 'text-text-muted' }, row.original.eventName),
    },
    {
        id: 'places',
        header: trans('Places'),
        cell: ({ row }) => String(row.original.places),
    },
    {
        accessorKey: 'version',
        header: trans('Version'),
        enableSorting: true,
        cell: ({ row }) => String(row.original.version),
    },
    {
        accessorKey: 'published_at',
        header: trans('Published'),
        enableSorting: true,
        cell: ({ row }) =>
            row.original.publishedAt === null
                ? h(
                      'span',
                      {
                          class: 'rounded-full bg-subtle px-2 py-0.5 text-xs text-text-muted',
                      },
                      trans('Draft'),
                  )
                : h(
                      'span',
                      { class: 'text-success' },
                      formatDateTime(row.original.publishedAt),
                  ),
    },
    {
        id: 'actions',
        header: '',
        cell: ({ row }) =>
            h('div', { class: 'flex justify-end gap-2' }, [
                h(
                    Link,
                    {
                        href: editPlan(row.original.id).url,
                        class: 'inline-flex h-8 items-center rounded-md border border-border px-3 text-sm hover:bg-subtle',
                    },
                    () => trans('Edit'),
                ),
                h(
                    Button,
                    {
                        variant: 'outline',
                        size: 'sm',
                        type: 'button',
                        onClick: () => togglePublish(row.original),
                    },
                    () =>
                        row.original.publishedAt === null
                            ? trans('Publish')
                            : trans('Unpublish'),
                ),
                h(
                    'a',
                    {
                        href: downloadPlan(row.original.id).url,
                        download: '',
                        class: 'inline-flex h-8 items-center rounded-md border border-border px-3 text-sm hover:bg-subtle',
                    },
                    trans('Export'),
                ),
            ]),
    },
];
</script>

<template>
    <Head :title="$t('Seating plans')" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="$t('Seating plans')"
            :description="
                $t(
                    'Hall plans per event: draw or import, publish when the picker may show them',
                )
            "
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            :filters="filterDefs"
            :search-placeholder="$t('Search plans…')"
            @update:search="setSearch"
            @update:per-page="setPerPage"
            @update:filter="setFilter"
        >
            <template #actions>
                <PlanImportDialog
                    :brands="props.brands"
                    :events="props.events"
                />
                <PlanCreateDialog
                    :brands="props.brands"
                    :events="props.events"
                />
            </template>
        </DataTableToolbar>

        <DataTable
            data-test="plans-table"
            :columns="columns"
            :data="props.plans.data"
            :sorting="sorting"
            :pagination="pagination"
            :items-label="$t('plans')"
            :empty-message="
                $t('No plans yet — create one or import a document.')
            "
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

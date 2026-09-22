<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
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
    create as createCompetition,
    edit as editCompetition,
    index as competitionsIndex,
    transition as transitionCompetition,
} from '@/routes/admin/competitions';

/**
 * The competitions of an event (design §9): status, entrants against the
 * limit, and the one thing to do next.
 *
 * "Next" is the lifecycle's forward arrow from where each competition stands
 * (design §4) — publish, close signup, start, finish. The backwards moves
 * (unpublish, reopen) and cancelling live on the competition's own screen,
 * where the consequence is spelled out; a list is the wrong place to cancel
 * a tournament from.
 *
 * The entrants count includes rosters that are still incomplete: they hold
 * their slot against the limit until the organizer fills or declines them.
 */
type CompetitionRow = Konventix.Competition.Data.CompetitionRowData;

interface PaginatedCompetitions {
    data: CompetitionRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    competitions: PaginatedCompetitions;
    brands: { id: string; name: string }[];
    events: { id: string; name: string; brandId: string }[];
    options: { status: string[] };
    filters: DataTableFilters & {
        brand: string | null;
        event: string | null;
        status: string | null;
    };
}>();

const { filters, setSearch, setFilter, toggleSort, setPerPage, setPage } =
    useDataTable(() => competitionsIndex().url, props.filters);

const filterDefs = computed<DataTableFilterDef[]>(() => [
    {
        name: 'brand',
        label: 'Brands',
        value: props.filters.brand,
        options: props.brands.map((brand) => ({
            value: brand.id,
            label: brand.name,
        })),
    },
    {
        name: 'event',
        label: 'Events',
        value: props.filters.event,
        options: props.events.map((event) => ({
            value: event.id,
            label: event.name,
        })),
    },
    {
        name: 'status',
        label: 'Competitions',
        value: props.filters.status,
        options: props.options.status.map((status) => ({
            value: status,
            label: status.replace('_', ' '),
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
    currentPage: props.competitions.current_page,
    lastPage: props.competitions.last_page,
    total: props.competitions.total,
    from: props.competitions.from,
    to: props.competitions.to,
}));

/** A state is never told by colour alone — the word is always there too. */
const statusClass: Record<string, string> = {
    draft: 'bg-subtle text-text-muted',
    published: 'bg-success/15 text-success',
    signup_closed: 'bg-warning/15 text-warning',
    running: 'bg-accent/15 text-accent',
    finished: 'bg-subtle text-text-muted',
    cancelled: 'bg-danger/15 text-danger',
};

function move(row: CompetitionRow): void {
    if (row.nextStatus === null) {
        return;
    }

    router.post(
        transitionCompetition(row.id).url,
        { status: row.nextStatus },
        { preserveScroll: true },
    );
}

const columns: ColumnDef<CompetitionRow>[] = [
    {
        accessorKey: 'name',
        header: 'Name',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                Link,
                {
                    href: editCompetition(row.original.id).url,
                    class: 'font-medium hover:text-accent',
                },
                () => row.original.name,
            ),
    },
    {
        id: 'event',
        header: 'Event',
        cell: ({ row }) =>
            h('span', { class: 'text-text-muted' }, row.original.eventName),
    },
    {
        id: 'discipline',
        header: 'Discipline',
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                row.original.disciplineName,
            ),
    },
    {
        id: 'format',
        header: 'Format',
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                row.original.stageFormat.replace(/_/g, ' '),
            ),
    },
    {
        accessorKey: 'status',
        header: 'Status',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                'span',
                {
                    class: `rounded-full px-2 py-0.5 text-xs font-medium ${statusClass[row.original.status] ?? 'bg-subtle text-text-muted'}`,
                },
                row.original.status.replace('_', ' '),
            ),
    },
    {
        id: 'entrants',
        header: 'Entrants',
        cell: ({ row }) =>
            `${row.original.entrants} / ${row.original.maxEntrants ?? '∞'}`,
    },
    {
        accessorKey: 'starts_at',
        header: 'Starts',
        enableSorting: true,
        cell: ({ row }) => formatDateTime(row.original.startsAt) || '—',
    },
    {
        id: 'next',
        header: 'Next',
        cell: ({ row }) =>
            row.original.nextStatus === null
                ? h('span', { class: 'text-text-muted' }, '—')
                : h(
                      'button',
                      {
                          type: 'button',
                          class: 'rounded-md border border-border px-2 py-1 text-xs font-medium hover:bg-subtle',
                          onClick: () => move(row.original),
                      },
                      row.original.nextLabel ?? '',
                  ),
    },
];
</script>

<template>
    <Head title="Competitions" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Competitions"
            description="The event's tournament programme, and where each contest stands"
        >
            <Button as-child>
                <Link :href="createCompetition().url">
                    <Plus class="size-4" />
                    New competition
                </Link>
            </Button>
        </AdminPageHeader>

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            :filters="filterDefs"
            search-placeholder="Search competitions…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
            @update:filter="setFilter"
        />

        <DataTable
            data-test="competitions-table"
            :columns="columns"
            :data="props.competitions.data"
            :sorting="sorting"
            :pagination="pagination"
            items-label="competitions"
            empty-message="No competitions yet — create one per contest of this event."
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

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
    edit as editDiscipline,
    index as disciplinesIndex,
} from '@/routes/admin/disciplines';
import DisciplineCreateDialog from '../../../components/admin/DisciplineCreateDialog.vue';
import { disciplineKindLabel } from '../../../components/public/labels';

/**
 * Disciplines (design §9): what is being contested and the defaults every
 * competition of it inherits — team size, the duration hint, draws
 * (ADR-0024, ADR-0062).
 *
 * The "Competitions" column says whether a discipline is still a free edit
 * or something people have already entered: team size freezes as soon as one
 * competition runs it, because that number decides which rosters count as
 * incomplete.
 *
 * Both "tabs" are toolbar filters declared once on the backend and once here
 * (.ai/rules/pages.md).
 */
type DisciplineRow = Konventix.Competition.Data.DisciplineRowData;

interface PaginatedDisciplines {
    data: DisciplineRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    disciplines: PaginatedDisciplines;
    brands: { id: string; name: string }[];
    options: {
        kind: string[];
        linkedProvider: { value: string; label: string }[];
    };
    filters: DataTableFilters & { brand: string | null; kind: string | null };
}>();

const { filters, setSearch, setFilter, toggleSort, setPerPage, setPage } =
    useDataTable(() => disciplinesIndex().url, props.filters);

const filterDefs = computed<DataTableFilterDef[]>(() => [
    {
        name: 'brand',
        label: trans('Brands'),
        value: props.filters.brand,
        options: props.brands.map((brand) => ({
            value: brand.id,
            label: brand.name,
        })),
    },
    {
        name: 'kind',
        label: trans('Kinds'),
        value: props.filters.kind,
        options: props.options.kind.map((kind) => ({
            value: kind,
            label: disciplineKindLabel(kind),
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
    currentPage: props.disciplines.current_page,
    lastPage: props.disciplines.last_page,
    total: props.disciplines.total,
    from: props.disciplines.from,
    to: props.disciplines.to,
}));

const columns: ColumnDef<DisciplineRow>[] = [
    {
        accessorKey: 'name',
        header: trans('Name'),
        enableSorting: true,
        cell: ({ row }) =>
            h(
                Link,
                {
                    href: editDiscipline(row.original.id).url,
                    class: 'font-medium hover:text-accent',
                },
                () => row.original.name,
            ),
    },
    {
        id: 'kind',
        header: trans('Kind'),
        cell: ({ row }) =>
            h(
                'span',
                {
                    class: 'rounded-full bg-subtle px-2 py-0.5 text-xs text-text-muted',
                },
                disciplineKindLabel(row.original.kind),
            ),
    },
    {
        id: 'brand',
        header: trans('Brand'),
        cell: ({ row }) =>
            h('span', { class: 'text-text-muted' }, row.original.brandName),
    },
    {
        accessorKey: 'team_size',
        header: trans('Team size'),
        enableSorting: true,
        cell: ({ row }) => String(row.original.teamSize),
    },
    {
        id: 'minutes',
        header: trans('Ø match minutes'),
        cell: ({ row }) => String(row.original.avgMatchMinutes),
    },
    {
        id: 'draws',
        header: trans('Draws'),
        cell: ({ row }) =>
            row.original.allowDraws ? trans('Yes') : trans('No'),
    },
    {
        id: 'competitions',
        header: trans('Competitions'),
        cell: ({ row }) => String(row.original.competitions),
    },
];
</script>

<template>
    <Head :title="$t('Disciplines')" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="$t('Disciplines')"
            :description="
                $t(
                    'What is contested, and the defaults every competition of it inherits',
                )
            "
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            :filters="filterDefs"
            :search-placeholder="$t('Search disciplines…')"
            @update:search="setSearch"
            @update:per-page="setPerPage"
            @update:filter="setFilter"
        >
            <template #actions>
                <DisciplineCreateDialog
                    :brands="props.brands"
                    :options="props.options"
                />
            </template>
        </DataTableToolbar>

        <DataTable
            data-test="disciplines-table"
            :columns="columns"
            :data="props.disciplines.data"
            :sorting="sorting"
            :pagination="pagination"
            :items-label="$t('disciplines')"
            :empty-message="
                $t(
                    'No disciplines yet — create one per thing people compete at.',
                )
            "
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

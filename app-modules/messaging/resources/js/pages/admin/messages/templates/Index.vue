<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import type { SortingState } from '@tanstack/vue-table';
import { computed } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import DataTable from '@/components/admin/DataTable.vue';
import DataTableToolbar from '@/components/admin/DataTableToolbar.vue';
import { useConsoleSelection } from '@/composables/useConsoleSelection';
import type {
    DataTableFilterDef,
    DataTableFilters,
} from '@/composables/useDataTable';
import { seedSelectionFilter, useDataTable } from '@/composables/useDataTable';
import {
    edit as editTemplate,
    index as templatesIndex,
} from '@/routes/admin/messages/templates';
import { columns } from './columns';
import type { TemplateRow } from './columns';

interface PaginatedTemplates {
    data: TemplateRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    templates: PaginatedTemplates;
    brands: { id: string; name: string }[];
    filters: DataTableFilters & { brand: string | null };
}>();

const selection = useConsoleSelection();

// `seedSelectionFilter` carries a cleared filter forward as `all` — but only
// when the shell has a pick to have cleared.
const { filters, setSearch, setFilter, toggleSort, setPerPage, setPage } =
    useDataTable(() => templatesIndex().url, {
        ...props.filters,
        brand: seedSelectionFilter(
            props.filters.brand,
            selection.value?.brandId,
        ),
    });

const filterDefs = computed<DataTableFilterDef[]>(() => [
    {
        name: 'brand',
        label: 'Brand',
        value: props.filters.brand,
        options: props.brands.map((brand) => ({
            value: brand.id,
            label: brand.name,
        })),
        clearValue: 'all',
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
    currentPage: props.templates.current_page,
    lastPage: props.templates.last_page,
    total: props.templates.total,
    from: props.templates.from,
    to: props.templates.to,
}));
</script>

<template>
    <Head title="Message templates" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Message templates"
            description="Rewrite a mail in your own words, preview it, then save the override — per brand"
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            :filters="filterDefs"
            search-placeholder="Search key or module…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
            @update:filter="setFilter"
        />

        <DataTable
            data-test="templates-table"
            :columns="columns"
            :data="templates.data"
            :sorting="sorting"
            :row-count="templates.total"
            :pagination="pagination"
            :get-row-id="(row: TemplateRow) => row.key"
            items-label="templates"
            empty-message="No templates registered."
            row-clickable
            @row-click="
                (row: TemplateRow) => router.visit(editTemplate(row.key))
            "
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

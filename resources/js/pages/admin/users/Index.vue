<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import type { SortingState } from '@tanstack/vue-table';
import { computed } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import DataTable from '@/components/admin/DataTable.vue';
import DataTableToolbar from '@/components/admin/DataTableToolbar.vue';
import type {
    DataTableFilterDef,
    DataTableFilters,
} from '@/composables/useDataTable';
import { useDataTable } from '@/composables/useDataTable';
import { edit as editUser, index as usersIndex } from '@/routes/admin/users';
import { columns } from './columns';
import type { UserRow } from './columns';

interface PaginatedUsers {
    data: UserRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    users: PaginatedUsers;
    brands: { id: string; name: string }[];
    filters: DataTableFilters & { brand: string | null };
}>();

const { filters, setSearch, setFilter, toggleSort, setPerPage, setPage } =
    useDataTable(() => usersIndex().url, props.filters);

/**
 * Accounts are operator-global, so the brand filter narrows by membership.
 * It defaults to the console selection; `clearValue: 'all'` makes "All
 * brands" survive the next visit instead of falling back to the selection.
 */
const filterDefs = computed<DataTableFilterDef[]>(() => [
    {
        name: 'brand',
        label: 'Brands',
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
    currentPage: props.users.current_page,
    lastPage: props.users.last_page,
    total: props.users.total,
    from: props.users.from,
    to: props.users.to,
}));
</script>

<template>
    <Head title="Users" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Users"
            description="Every account across the operator's brands"
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            :filters="filterDefs"
            search-placeholder="Search users…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
            @update:filter="setFilter"
        />

        <DataTable
            data-test="users-table"
            :columns="columns"
            :data="users.data"
            :sorting="sorting"
            :row-count="users.total"
            :pagination="pagination"
            :get-row-id="(row: UserRow) => row.id"
            items-label="users"
            empty-message="No users found."
            row-clickable
            @row-click="(row: UserRow) => router.visit(editUser(row.id))"
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

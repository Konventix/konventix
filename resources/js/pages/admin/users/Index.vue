<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { SortingState } from '@tanstack/vue-table';
import { computed } from 'vue';
import DataTable from '@/components/admin/DataTable.vue';
import DataTableToolbar from '@/components/admin/DataTableToolbar.vue';
import type { DataTableFilters } from '@/composables/useDataTable';
import { useDataTable } from '@/composables/useDataTable';
import { index as usersIndex } from '@/routes/admin/users';
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
    filters: DataTableFilters;
}>();

const { filters, setSearch, toggleSort, setPerPage, setPage } = useDataTable(
    () => usersIndex().url,
    props.filters,
);

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
        <h1 class="text-2xl font-semibold">Users</h1>

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            search-placeholder="Search users…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
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
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

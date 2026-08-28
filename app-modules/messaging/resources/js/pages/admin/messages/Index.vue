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
    index as messagesIndex,
    show as showMessage,
} from '@/routes/admin/messages';
import { columns } from './columns';
import type { MessageRow } from './columns';

interface PaginatedMessages {
    data: MessageRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    messages: PaginatedMessages;
    brands: { id: string; name: string }[];
    statuses: string[];
    channels: string[];
    templates: string[];
    filters: DataTableFilters & {
        brand: string | null;
        status: string | null;
        channel: string | null;
        template: string | null;
    };
}>();

const selection = useConsoleSelection();

// `seedSelectionFilter` carries a cleared filter forward as `all` — but only
// when the shell has a pick to have cleared.
const { filters, setSearch, setFilter, toggleSort, setPerPage, setPage } =
    useDataTable(() => messagesIndex().url, {
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
    {
        name: 'status',
        label: 'Status',
        value: props.filters.status,
        options: props.statuses.map((status) => ({
            value: status,
            label: status[0].toUpperCase() + status.slice(1),
        })),
    },
    {
        name: 'channel',
        label: 'Channel',
        value: props.filters.channel,
        options: props.channels.map((channel) => ({
            value: channel,
            label: channel,
        })),
    },
    {
        name: 'template',
        label: 'Template',
        value: props.filters.template,
        options: props.templates.map((template) => ({
            value: template,
            label: template,
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
    currentPage: props.messages.current_page,
    lastPage: props.messages.last_page,
    total: props.messages.total,
    from: props.messages.from,
    to: props.messages.to,
}));
</script>

<template>
    <Head title="Messages" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Messages"
            description="The delivery log — what Messaging sent, and what happened to it"
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            :filters="filterDefs"
            search-placeholder="Search recipient email…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
            @update:filter="setFilter"
        />

        <DataTable
            data-test="messages-table"
            :columns="columns"
            :data="messages.data"
            :sorting="sorting"
            :row-count="messages.total"
            :pagination="pagination"
            :get-row-id="(row: MessageRow) => row.id"
            items-label="messages"
            empty-message="No messages found."
            row-clickable
            @row-click="(row: MessageRow) => router.visit(showMessage(row.id))"
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

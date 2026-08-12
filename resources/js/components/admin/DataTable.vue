<script setup lang="ts" generic="TData">
import { ChevronDown, ChevronLeft, ChevronRight, ChevronUp } from '@lucide/vue';
import { FlexRender, getCoreRowModel, useVueTable } from '@tanstack/vue-table';
import type { ColumnDef, SortingState, Updater } from '@tanstack/vue-table';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

/**
 * Generic server-driven data table (ADR-0029 operator console), ported
 * concept from LanCore's per-page table markup + `useDataTable` composable.
 * Sorting, filtering and pagination are all manual — the parent page owns the
 * query params via `useDataTable` and re-requests the Inertia page.
 */
export interface DataTablePagination {
    currentPage: number;
    lastPage: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = withDefaults(
    defineProps<{
        columns: ColumnDef<TData, any>[];
        data: TData[];
        sorting?: SortingState;
        rowCount?: number;
        getRowId?: (row: TData) => string;
        emptyMessage?: string;
        pagination?: DataTablePagination;
        itemsLabel?: string;
        /** Highlights rows on hover and shows a pointer cursor when the parent listens for `rowClick`. */
        rowClickable?: boolean;
    }>(),
    {
        sorting: () => [],
        rowCount: undefined,
        getRowId: undefined,
        emptyMessage: 'No results found.',
        pagination: undefined,
        itemsLabel: 'results',
        rowClickable: false,
    },
);

const emit = defineEmits<{
    (e: 'update:sorting', sorting: SortingState): void;
    (e: 'update:page', page: number): void;
    (e: 'rowClick', row: TData): void;
}>();

const table = useVueTable({
    get data() {
        return props.data;
    },
    columns: props.columns,
    getCoreRowModel: getCoreRowModel(),
    manualSorting: true,
    manualFiltering: true,
    manualPagination: true,
    rowCount: props.rowCount,
    getRowId: props.getRowId,
    state: {
        get sorting() {
            return props.sorting;
        },
    },
    onSortingChange: (updater: Updater<SortingState>) => {
        const next =
            typeof updater === 'function'
                ? updater(props.sorting ?? [])
                : updater;

        emit('update:sorting', next);
    },
});

const hasPagination = computed(() => props.pagination !== undefined);
</script>

<template>
    <div class="rounded-xl border border-border">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr
                        v-for="headerGroup in table.getHeaderGroups()"
                        :key="headerGroup.id"
                        class="border-b border-border text-left text-text-muted"
                    >
                        <th
                            v-for="header in headerGroup.headers"
                            :key="header.id"
                            class="p-3 font-medium"
                            :class="
                                header.column.getCanSort()
                                    ? 'cursor-pointer select-none'
                                    : ''
                            "
                            @click="
                                header.column.getCanSort()
                                    ? header.column.toggleSorting()
                                    : undefined
                            "
                        >
                            <span
                                v-if="!header.isPlaceholder"
                                class="inline-flex items-center gap-1"
                            >
                                <FlexRender
                                    :render="header.column.columnDef.header"
                                    :props="header.getContext()"
                                />
                                <template v-if="header.column.getCanSort()">
                                    <ChevronUp
                                        v-if="
                                            header.column.getIsSorted() ===
                                            'asc'
                                        "
                                        class="size-3.5"
                                    />
                                    <ChevronDown
                                        v-else-if="
                                            header.column.getIsSorted() ===
                                            'desc'
                                        "
                                        class="size-3.5"
                                    />
                                </template>
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template v-if="table.getRowModel().rows.length">
                        <tr
                            v-for="row in table.getRowModel().rows"
                            :key="row.id"
                            class="border-b border-border last:border-0"
                            :class="
                                rowClickable
                                    ? 'cursor-pointer hover:bg-surface-muted'
                                    : ''
                            "
                            @click="emit('rowClick', row.original)"
                        >
                            <td
                                v-for="cell in row.getVisibleCells()"
                                :key="cell.id"
                                class="p-3"
                            >
                                <FlexRender
                                    :render="cell.column.columnDef.cell"
                                    :props="cell.getContext()"
                                />
                            </td>
                        </tr>
                    </template>
                    <tr v-else>
                        <td
                            :colspan="columns.length"
                            class="p-6 text-center text-text-muted"
                        >
                            {{ emptyMessage }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="hasPagination && pagination"
            class="flex items-center justify-between border-t border-border px-4 py-3"
        >
            <span class="text-xs text-text-muted">
                <template v-if="pagination.from && pagination.to">
                    Showing {{ pagination.from }}–{{ pagination.to }} of
                    {{ pagination.total }} {{ itemsLabel }}
                </template>
                <template v-else>
                    {{ pagination.total }} {{ itemsLabel }}
                </template>
            </span>
            <div class="flex items-center gap-1">
                <Button
                    variant="outline"
                    size="icon-sm"
                    :disabled="pagination.currentPage <= 1"
                    @click="emit('update:page', pagination.currentPage - 1)"
                >
                    <ChevronLeft class="size-4" />
                </Button>
                <span class="px-2 text-xs text-text-muted">
                    {{ pagination.currentPage }} / {{ pagination.lastPage }}
                </span>
                <Button
                    variant="outline"
                    size="icon-sm"
                    :disabled="pagination.currentPage >= pagination.lastPage"
                    @click="emit('update:page', pagination.currentPage + 1)"
                >
                    <ChevronRight class="size-4" />
                </Button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts" generic="TData">
import { ChevronDown, ChevronLeft, ChevronRight, ChevronUp } from '@lucide/vue';
import { FlexRender, getCoreRowModel, useVueTable } from '@tanstack/vue-table';
import type {
    Column,
    ColumnDef,
    SortingState,
    Updater,
} from '@tanstack/vue-table';
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
        /**
         * Highlights rows on hover and shows a pointer cursor when the parent
         * listens for `rowClick`.
         *
         * The row click is a mouse convenience and never the only way in: a
         * `<tr>` cannot take focus, so a page that sets this must also render
         * a real link to the same destination in one of the row's cells, or
         * the destination is unreachable by keyboard (WCAG 2.1.1).
         */
        rowClickable?: boolean;
    }>(),
    {
        sorting: () => [],
        rowCount: undefined,
        getRowId: undefined,
        emptyMessage: undefined,
        pagination: undefined,
        itemsLabel: undefined,
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

/**
 * `aria-sort` for a header cell. Only the sortable ones carry it at all —
 * on a column that cannot be sorted the attribute would claim an
 * unsorted-but-sortable column, which is a different statement.
 */
function ariaSort(
    column: Column<TData, unknown>,
): 'ascending' | 'descending' | 'none' | undefined {
    if (!column.getCanSort()) {
        return undefined;
    }

    return (
        {
            asc: 'ascending' as const,
            desc: 'descending' as const,
        }[column.getIsSorted() as 'asc' | 'desc'] ?? 'none'
    );
}
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
                            scope="col"
                            class="p-3 font-medium"
                            :aria-sort="ariaSort(header.column)"
                        >
                            <template v-if="!header.isPlaceholder">
                                <!--
                                    A sortable header is a button, not a
                                    clickable cell: a `th` carrying a click
                                    handler cannot be reached or fired from
                                    the keyboard at all (WCAG 2.1.1), and
                                    `aria-sort` on the cell is what announces
                                    which way the column is ordered.
                                -->
                                <button
                                    v-if="header.column.getCanSort()"
                                    type="button"
                                    class="inline-flex cursor-pointer items-center gap-1 select-none"
                                    @click="header.column.toggleSorting()"
                                >
                                    <FlexRender
                                        :render="header.column.columnDef.header"
                                        :props="header.getContext()"
                                    />
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
                                </button>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1"
                                >
                                    <FlexRender
                                        :render="header.column.columnDef.header"
                                        :props="header.getContext()"
                                    />
                                </span>
                            </template>
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
                            {{ emptyMessage ?? $t('No results found.') }}
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
                    {{
                        $t('Showing :first–:last of :total :items', {
                            first: String(pagination.from),
                            last: String(pagination.to),
                            total: String(pagination.total),
                            items: itemsLabel ?? $t('results'),
                        })
                    }}
                </template>
                <template v-else>
                    {{
                        $t(':total :items', {
                            total: String(pagination.total),
                            items: itemsLabel ?? $t('results'),
                        })
                    }}
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
                    <span class="sr-only">{{ $t('Previous page') }}</span>
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
                    <span class="sr-only">{{ $t('Next page') }}</span>
                </Button>
            </div>
        </div>
    </div>
</template>

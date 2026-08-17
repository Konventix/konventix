<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import { computed, ref } from 'vue';
import { h } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import DataTable from '@/components/admin/DataTable.vue';
import DataTableToolbar from '@/components/admin/DataTableToolbar.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { DataTableFilters } from '@/composables/useDataTable';
import { useDataTable } from '@/composables/useDataTable';
import { document as invoiceDocument } from '@/routes/admin/invoices';
import {
    bookPayment,
    index as ordersIndex,
    show as showOrder,
} from '@/routes/admin/orders';
import { columns as baseColumns } from './columns';
import type { OrderRow } from './columns';

interface PaginatedOrders {
    data: OrderRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    orders: PaginatedOrders;
    filters: DataTableFilters;
}>();

const { filters, setSearch, toggleSort, setPerPage, setPage } = useDataTable(
    () => ordersIndex().url,
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
    currentPage: props.orders.current_page,
    lastPage: props.orders.last_page,
    total: props.orders.total,
    from: props.orders.from,
    to: props.orders.to,
}));

/** The order awaiting "really book this payment?" confirmation. */
const bookingOrder = ref<OrderRow | null>(null);
const booking = ref(false);

function confirmBooking(): void {
    if (bookingOrder.value === null) {
        return;
    }

    booking.value = true;
    router.post(
        bookPayment(bookingOrder.value.id).url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                booking.value = false;
                bookingOrder.value = null;
            },
        },
    );
}

/**
 * Actions live here rather than in columns.ts because they need the page's
 * dialog state and route helpers.
 */
const columns: ColumnDef<OrderRow>[] = [
    ...baseColumns,
    {
        id: 'actions',
        header: '',
        enableSorting: false,
        cell: ({ row }) => {
            const cells = [];

            if (row.original.invoice?.documentReady) {
                cells.push(
                    h(
                        'a',
                        {
                            href: invoiceDocument(row.original.invoice.id).url,
                            class: 'inline-flex items-center gap-1 text-sm text-text-muted hover:text-text',
                        },
                        [h(Download, { class: 'size-4' }), 'PDF'],
                    ),
                );
            }

            if (row.original.status === 'pending') {
                cells.push(
                    h(
                        Button,
                        {
                            variant: 'secondary',
                            size: 'sm',
                            onClick: () => (bookingOrder.value = row.original),
                        },
                        () => 'Book payment',
                    ),
                );
            }

            return h('div', { class: 'flex items-center justify-end gap-3' }, cells);
        },
    },
];
</script>

<template>
    <Head title="Orders" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Orders"
            description="Money owed and settled across registrations"
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            search-placeholder="Search by invoice number or registration…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
        />

        <DataTable
            data-test="orders-table"
            :columns="columns"
            :data="props.orders.data"
            :sorting="sorting"
            :pagination="pagination"
            items-label="orders"
            empty-message="No orders yet — Commerce wakes up with the first paid registration."
            row-clickable
            @row-click="(row: OrderRow) => router.visit(showOrder(row.id))"
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />

        <Dialog
            :open="bookingOrder !== null"
            @update:open="(value) => !value && (bookingOrder = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Book manual payment</DialogTitle>
                    <DialogDescription>
                        Mark
                        <span class="font-medium">{{
                            bookingOrder?.invoice?.number ?? bookingOrder?.id
                        }}</span>
                        ({{ bookingOrder?.totalGross }}
                        {{ bookingOrder?.currency }}) as paid by bank
                        transfer? Registration will be notified and an
                        on-payment confirmation completes immediately.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="secondary"
                        :disabled="booking"
                        @click="bookingOrder = null"
                    >
                        Cancel
                    </Button>
                    <Button :disabled="booking" @click="confirmBooking">
                        Book payment
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>

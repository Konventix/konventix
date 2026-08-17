<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, Download } from '@lucide/vue';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import { computed, h, ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import DataTable from '@/components/admin/DataTable.vue';
import DataTableToolbar from '@/components/admin/DataTableToolbar.vue';
import { Button } from '@/components/ui/button';
import type { DataTableFilters } from '@/composables/useDataTable';
import { useDataTable } from '@/composables/useDataTable';
import {
    bulkApprove,
    exportMethod as exportRegistrations,
    index as registrationsIndex,
} from '@/routes/admin/registrations';

type RegistrationRow = {
    id: string;
    eventId: string;
    registrant: string;
    registrantEmail: string | null;
    organization: string | null;
    status: string;
    paymentStatus: string;
    submittedAt: string;
    approvable: boolean;
};

interface PaginatedRegistrations {
    data: RegistrationRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    registrations: PaginatedRegistrations;
    filters: DataTableFilters & { status: string | null; event: string | null };
}>();

const { filters, setSearch, toggleSort, setPerPage, setPage } = useDataTable(
    () => registrationsIndex().url,
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
    currentPage: props.registrations.current_page,
    lastPage: props.registrations.last_page,
    total: props.registrations.total,
    from: props.registrations.from,
    to: props.registrations.to,
}));

function setStatus(event: Event): void {
    const status = (event.target as HTMLSelectElement).value || undefined;
    router.get(
        registrationsIndex().url,
        { ...routeQuery(), status },
        { preserveState: false },
    );
}

function routeQuery(): Record<string, string | undefined> {
    return {
        search: filters.search || undefined,
        status: props.filters.status ?? undefined,
        event: props.filters.event ?? undefined,
    };
}

const selected = ref<Set<string>>(new Set());

function toggleSelection(id: string): void {
    if (selected.value.has(id)) {
        selected.value.delete(id);
    } else {
        selected.value.add(id);
    }
    selected.value = new Set(selected.value);
}

function approveSelected(): void {
    router.post(
        bulkApprove().url,
        { ids: [...selected.value] },
        {
            preserveScroll: true,
            onSuccess: () => (selected.value = new Set()),
        },
    );
}

const statusClasses: Record<string, string> = {
    submitted: 'bg-warning/15 text-warning',
    approved: 'bg-accent/15 text-accent',
    confirmed: 'bg-success/15 text-success',
    rejected: 'bg-danger/15 text-danger',
    cancelled: 'bg-subtle text-text-muted',
};

const columns: ColumnDef<RegistrationRow>[] = [
    {
        id: 'select',
        header: '',
        enableSorting: false,
        cell: ({ row }) =>
            row.original.approvable
                ? h('input', {
                      type: 'checkbox',
                      checked: selected.value.has(row.original.id),
                      onChange: () => toggleSelection(row.original.id),
                  })
                : null,
    },
    {
        id: 'registrant',
        header: 'Registrant',
        enableSorting: false,
        cell: ({ row }) =>
            h('div', { class: 'flex flex-col' }, [
                h('span', { class: 'font-medium' }, row.original.registrant),
                row.original.registrantEmail
                    ? h(
                          'span',
                          { class: 'text-xs text-text-muted' },
                          row.original.registrantEmail,
                      )
                    : null,
            ]),
    },
    {
        id: 'organization',
        header: 'Organization',
        enableSorting: false,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                row.original.organization ?? '—',
            ),
    },
    {
        accessorKey: 'status',
        header: 'Status',
        enableSorting: true,
        cell: ({ row }) => {
            const status = row.getValue<string>('status');
            return h(
                'span',
                {
                    class: `inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${statusClasses[status] ?? 'bg-subtle text-text-muted'}`,
                },
                status,
            );
        },
    },
    {
        accessorKey: 'payment_status',
        accessorFn: (row) => row.paymentStatus,
        header: 'Payment',
        enableSorting: true,
        cell: ({ row }) =>
            h('span', { class: 'text-text-muted' }, row.original.paymentStatus),
    },
    {
        accessorKey: 'submitted_at',
        accessorFn: (row) => row.submittedAt,
        header: 'Submitted',
        enableSorting: true,
        cell: ({ row }) =>
            h('span', { class: 'text-text-muted' }, row.original.submittedAt),
    },
];
</script>

<template>
    <Head title="Registrations" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Registrations"
            description="Submissions, approvals and the waitlist"
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            search-placeholder="Search registrant, organization, email…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
        >
            <template #actions>
                <select
                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                    :value="props.filters.status ?? ''"
                    @change="setStatus"
                >
                    <option value="">All statuses</option>
                    <option value="submitted">Submitted</option>
                    <option value="approved">Approved</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="rejected">Rejected</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <Button
                    v-if="selected.size > 0"
                    variant="secondary"
                    @click="approveSelected"
                >
                    <Check class="size-4" />
                    Approve {{ selected.size }}
                </Button>
                <a :href="exportRegistrations().url">
                    <Button variant="outline">
                        <Download class="size-4" />
                        CSV
                    </Button>
                </a>
            </template>
        </DataTableToolbar>

        <DataTable
            data-test="registrations-table"
            :columns="columns"
            :data="props.registrations.data"
            :sorting="sorting"
            :pagination="pagination"
            items-label="registrations"
            empty-message="No registrations match."
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

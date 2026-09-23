<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import type { SortingState } from '@tanstack/vue-table';
import { computed } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import DataTable from '@/components/admin/DataTable.vue';
import DataTableToolbar from '@/components/admin/DataTableToolbar.vue';
import { Button } from '@/components/ui/button';
import type {
    DataTableFilterDef,
    DataTableFilters,
} from '@/composables/useDataTable';
import { useDataTable } from '@/composables/useDataTable';
import { show as showEvent } from '@/routes/admin/events';
import { index as attendeesIndex } from '@/routes/admin/events/attendees';
import { attendeeColumns } from '../../../components/attendees/columns';
import type { AttendeeRow } from '../../../components/attendees/columns';

/**
 * The attendee list of one event (attendee-list design §4.1, §4.2, #329):
 * one row per ticket, one search over every listed field, and the four
 * filters of §4.1 as two toolbar selects declared once on the server
 * (`AttendeeListQuery::FILTERS`) and once here (`.ai/rules/pages.md`).
 */
interface PaginatedAttendees {
    data: AttendeeRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    event: { id: string; name: string };
    attendees: PaginatedAttendees;
    filters: DataTableFilters & {
        presence: string | null;
        view: string | null;
    };
    canPrintBadge: boolean;
}>();

const { filters, setSearch, setFilter, toggleSort, setPerPage, setPage } =
    useDataTable(() => attendeesIndex(props.event.id).url, {
        ...props.filters,
    });

const filterDefs = computed<DataTableFilterDef[]>(() => [
    {
        name: 'presence',
        label: 'Attendees',
        value: props.filters.presence,
        options: [
            { value: 'in', label: 'Checked in' },
            { value: 'not_in', label: 'Not checked in' },
        ],
    },
    {
        name: 'view',
        label: 'Tickets',
        value: props.filters.view,
        options: [
            { value: 'unassigned', label: 'Unassigned' },
            { value: 'revoked', label: 'Revoked' },
        ],
    },
]);

const emptyMessage = computed(() =>
    props.filters.search || props.filters.presence || props.filters.view
        ? 'No ticket matches this search and these filters.'
        : 'No tickets for this event yet.',
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
    currentPage: props.attendees.current_page,
    lastPage: props.attendees.last_page,
    total: props.attendees.total,
    from: props.attendees.from,
    to: props.attendees.to,
}));
</script>

<template>
    <Head :title="`Attendees · ${event.name}`" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Attendees"
            :description="`${event.name} — every ticket, who holds it, and whether they are in today`"
        >
            <Button as-child variant="outline" size="sm">
                <Link :href="showEvent(event.id).url">
                    <ArrowLeft class="size-4" />
                    Event
                </Link>
            </Button>
        </AdminPageHeader>

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 25"
            :filters="filterDefs"
            search-placeholder="Search by name, username, email, ticket, registration or type…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
            @update:filter="setFilter"
        />

        <DataTable
            data-test="attendees-table"
            :columns="attendeeColumns"
            :data="props.attendees.data"
            :get-row-id="(row: AttendeeRow) => row.admissionId"
            :sorting="sorting"
            :pagination="pagination"
            items-label="tickets"
            :empty-message="emptyMessage"
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

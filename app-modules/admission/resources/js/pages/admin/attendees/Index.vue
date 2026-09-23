<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import type { SortingState } from '@tanstack/vue-table';
import { trans } from 'laravel-vue-i18n';
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
    /**
     * No day of the event's calendar covers now: every check-in state is the
     * last one known, not today's, and nobody can be checked in or out.
     */
    outsideEventDay: boolean;
}>();

const { filters, setSearch, setFilter, toggleSort, setPerPage, setPage } =
    useDataTable(() => attendeesIndex(props.event.id).url, {
        ...props.filters,
    });

const filterDefs = computed<DataTableFilterDef[]>(() => [
    {
        name: 'presence',
        label: trans('Attendees'),
        value: props.filters.presence,
        options: [
            { value: 'in', label: trans('Checked in') },
            { value: 'not_in', label: trans('Not checked in') },
        ],
    },
    {
        name: 'view',
        label: trans('Tickets'),
        value: props.filters.view,
        options: [
            { value: 'unassigned', label: trans('Unassigned') },
            { value: 'revoked', label: trans('Revoked') },
        ],
    },
]);

const emptyMessage = computed(() =>
    props.filters.search || props.filters.presence || props.filters.view
        ? trans('No ticket matches this search and these filters.')
        : trans('No tickets for this event yet.'),
);

const columns = attendeeColumns();

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
    <Head :title="$t('Attendees · :event', { event: event.name })" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="$t('Attendees')"
            :description="
                $t(
                    ':event — every ticket, who holds it, and whether they are in today',
                    { event: event.name },
                )
            "
        >
            <Button as-child variant="outline" size="sm">
                <Link :href="showEvent(event.id).url">
                    <ArrowLeft class="size-4" />
                    {{ $t('Event') }}
                </Link>
            </Button>
        </AdminPageHeader>

        <p
            v-if="props.outsideEventDay"
            role="status"
            class="rounded-lg border border-border bg-subtle px-3 py-2 text-sm text-text-muted"
            data-test="attendees-outside-event-day"
        >
            {{
                $t(
                    "No event day covers today. The check-in column shows each ticket's last known state, and nobody can be checked in or out until the next event day.",
                )
            }}
        </p>

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 25"
            :filters="filterDefs"
            :search-placeholder="
                $t(
                    'Search by name, username, email, ticket, registration or type…',
                )
            "
            @update:search="setSearch"
            @update:per-page="setPerPage"
            @update:filter="setFilter"
        />

        <DataTable
            data-test="attendees-table"
            :columns="columns"
            :data="props.attendees.data"
            :get-row-id="(row: AttendeeRow) => row.admissionId"
            :sorting="sorting"
            :pagination="pagination"
            :items-label="$t('tickets')"
            :empty-message="emptyMessage"
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

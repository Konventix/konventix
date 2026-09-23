<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, Download } from '@lucide/vue';
import type { SortingState } from '@tanstack/vue-table';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import DataTable from '@/components/admin/DataTable.vue';
import DataTableToolbar from '@/components/admin/DataTableToolbar.vue';
import { Button } from '@/components/ui/button';
import { useConsoleSelection } from '@/composables/useConsoleSelection';
import type {
    DataTableFilterDef,
    DataTableFilters,
} from '@/composables/useDataTable';
import { seedSelectionFilter, useDataTable } from '@/composables/useDataTable';
import {
    bulkApprove,
    exportMethod as exportRegistrations,
    index as registrationsIndex,
} from '@/routes/admin/registrations';
import ReduceQuantityDialog from '../../../components/registrations/ReduceQuantityDialog.vue';
import { registrationColumns } from '../../../components/registrations/rows';
import type { RegistrationRow } from '../../../components/registrations/rows';
import {
    assertionStateLabel,
    registrationStatusLabel,
} from '../../../lib/labels';

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
    filters: DataTableFilters & {
        status: string | null;
        brand: string | null;
        event: string | null;
        condition: string | null;
    };
    statuses: string[];
    /** The four assertion states plus `none` — the worklist (D-REG-22). */
    conditionStates: string[];
    brands: { id: string; name: string }[];
    events: { id: string; name: string }[];
    canReduceQuantity: boolean;
    reductionReasons: string[];
}>();

const selection = useConsoleSelection();

// `seedSelectionFilter` carries a cleared filter forward as `all` — but only
// when the shell has a pick to have cleared.
const { filters, setSearch, setFilter, toggleSort, setPerPage, setPage } =
    useDataTable(() => registrationsIndex().url, {
        ...props.filters,
        brand: seedSelectionFilter(
            props.filters.brand,
            selection.value?.brandId,
        ),
        event: seedSelectionFilter(
            props.filters.event,
            selection.value?.eventId,
        ),
    });

/**
 * Picking a brand in the toolbar drops the event filter, exactly as
 * `AdminSelectionBar.pickBrand` drops it in the shell: the event belonged to
 * the brand that was just replaced. Left standing it would ask for
 * `brand=Y AND event=E` — no rows, and a blank event trigger, because Y's
 * options never contain E. `setFilter` stays generic; this is the list's rule.
 */
function onFilterChange(name: string, value: string | null): void {
    if (name === 'brand') {
        filters.event = undefined;
    }

    setFilter(name, value);
}

/**
 * Brand and event default to the console selection; `clearValue: 'all'` makes
 * "All brands" / "All events" survive the next visit instead of falling back
 * to the selection again. Event names come from Registration's own read model.
 */
const filterDefs = computed<DataTableFilterDef[]>(() => [
    {
        name: 'brand',
        label: trans('Brands'),
        value: props.filters.brand,
        options: props.brands.map((brand) => ({
            value: brand.id,
            label: brand.name,
        })),
        clearValue: 'all',
    },
    {
        name: 'event',
        label: trans('Events'),
        value: props.filters.event,
        options: props.events.map((event) => ({
            value: event.id,
            label: event.name,
        })),
        clearValue: 'all',
    },
    {
        name: 'status',
        label: trans('Status'),
        value: props.filters.status,
        options: props.statuses.map((status) => ({
            value: status,
            label: registrationStatusLabel(status),
        })),
    },
    // D-REG-22: for an `operator_checked` condition this filter **is** the
    // worklist — "Asserted" is the undecided pile. It narrows the list and
    // gates nothing.
    {
        name: 'condition',
        label: trans('Entry condition'),
        value: props.filters.condition,
        options: props.conditionStates.map((state) => ({
            value: state,
            label:
                state === 'none'
                    ? trans('Nothing declared')
                    : assertionStateLabel(state),
        })),
    },
]);

/**
 * The export honours the same filters the list is showing, so the link has to
 * carry them — an unqualified href would quietly export everything.
 */
const exportHref = computed(() =>
    exportRegistrations.url({
        query: {
            search: filters.search,
            status: filters.status,
            brand: filters.brand,
            event: filters.event,
            condition: filters.condition,
        },
    }),
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

// D-REG-15: the row being reduced, or null. The dialog owns its own form.
const reducing = ref<RegistrationRow | null>(null);

const columns = computed(() =>
    registrationColumns({
        isSelected: (id) => selected.value.has(id),
        onToggle: toggleSelection,
        canReduce: props.canReduceQuantity,
        onReduce: (row) => (reducing.value = row),
    }),
);
</script>

<template>
    <Head :title="$t('Registrations')" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="$t('Registrations')"
            :description="$t('Submissions, approvals and the waitlist')"
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            :filters="filterDefs"
            :search-placeholder="$t('Search registrant, organization, email…')"
            @update:search="setSearch"
            @update:per-page="setPerPage"
            @update:filter="onFilterChange"
        >
            <template #actions>
                <Button
                    v-if="selected.size > 0"
                    variant="secondary"
                    @click="approveSelected"
                >
                    <Check class="size-4" />
                    {{ $t('Approve :count', { count: String(selected.size) }) }}
                </Button>
                <a :href="exportHref">
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
            :items-label="$t('registrations')"
            :empty-message="$t('No registrations match.')"
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />

        <ReduceQuantityDialog
            :registration="reducing"
            :reasons="props.reductionReasons"
            @close="reducing = null"
        />
    </div>
</template>

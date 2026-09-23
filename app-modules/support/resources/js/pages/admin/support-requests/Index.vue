<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { SortingState } from '@tanstack/vue-table';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import DataTable from '@/components/admin/DataTable.vue';
import DataTableToolbar from '@/components/admin/DataTableToolbar.vue';
import type {
    DataTableFilterDef,
    DataTableFilters,
} from '@/composables/useDataTable';
import { useDataTable } from '@/composables/useDataTable';
import { index as supportRequestsIndex } from '@/routes/admin/support/requests';
import { supportRequestColumns } from '../../../components/requests/rows';
import type { SupportRequestRow } from '../../../components/requests/rows';
import {
    supportCategoryLabel,
    supportStateLabel,
} from '../../../components/requests/supportConsole';

/**
 * The support inbox (design §9.1).
 *
 * Four toolbar filters and no tab strip, the console's own convention
 * (`.ai/rules/pages.md`). The state filter is the one that matters: it
 * defaults to the open pile on the server, and clearing it sends the
 * literal `all` so the cleared view survives the next sort — an inbox whose
 * first screen is last month's resolved reports is not an inbox.
 *
 * The brand filter carries a `Console` option beside the brands, because a
 * report filed on the operator host belongs to no brand at all (ADR-0063)
 * and "no brand" is a question a brand picker cannot otherwise ask.
 */
interface PaginatedRequests {
    data: SupportRequestRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    requests: PaginatedRequests;
    filters: DataTableFilters & {
        state: string | null;
        category: string | null;
        brand: string | null;
        known_issue: string | null;
    };
    states: Konventix.Support.Enums.SupportRequestState[];
    categories: Konventix.Support.Enums.SupportCategory[];
    brands: { id: string; name: string }[];
    canTriage: boolean;
}>();

const { filters, setSearch, setFilter, toggleSort, setPerPage, setPage } =
    useDataTable(() => supportRequestsIndex().url, { ...props.filters });

const filterDefs = computed<DataTableFilterDef[]>(() => [
    {
        name: 'state',
        label: trans('States'),
        value: props.filters.state,
        options: [
            {
                value: 'open',
                label: trans('Open (received, seen, in progress)'),
            },
            ...props.states.map((state) => ({
                value: state,
                label: supportStateLabel(state),
            })),
        ],
        // "All states" has to keep being said out loud, or the server's
        // default — the open pile — takes the filter back on the next visit.
        clearValue: 'all',
    },
    {
        name: 'category',
        label: trans('Categories'),
        value: props.filters.category,
        options: props.categories.map((category) => ({
            value: category,
            label: supportCategoryLabel(category),
        })),
    },
    {
        name: 'brand',
        label: trans('Brands'),
        value: props.filters.brand,
        options: [
            { value: 'console', label: trans('Console (no brand)') },
            ...props.brands.map((brand) => ({
                value: brand.id,
                label: brand.name,
            })),
        ],
    },
    {
        name: 'known_issue',
        label: trans('Known issue'),
        value: props.filters.known_issue,
        options: [
            { value: 'yes', label: trans('Marked') },
            { value: 'no', label: trans('Not marked') },
        ],
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
    currentPage: props.requests.current_page,
    lastPage: props.requests.last_page,
    total: props.requests.total,
    from: props.requests.from,
    to: props.requests.to,
}));

const columns = computed(() => supportRequestColumns());
</script>

<template>
    <Head :title="$t('Support requests')" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="$t('Support requests')"
            :description="
                props.canTriage
                    ? $t(
                          'What visitors and operators report, across every brand',
                      )
                    : $t(
                          'What visitors and operators report, across every brand — read only',
                      )
            "
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            :filters="filterDefs"
            :search-placeholder="
                $t('Search reference, message, external reference…')
            "
            @update:search="setSearch"
            @update:per-page="setPerPage"
            @update:filter="setFilter"
        />

        <DataTable
            data-test="support-requests-table"
            :columns="columns"
            :data="props.requests.data"
            :sorting="sorting"
            :pagination="pagination"
            :items-label="$t('support requests')"
            :empty-message="$t('No reports match.')"
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

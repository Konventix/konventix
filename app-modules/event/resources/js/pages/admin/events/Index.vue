<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import { computed, h, ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import DataTable from '@/components/admin/DataTable.vue';
import DataTableToolbar from '@/components/admin/DataTableToolbar.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useConsoleSelection } from '@/composables/useConsoleSelection';
import type {
    DataTableFilterDef,
    DataTableFilters,
} from '@/composables/useDataTable';
import { seedSelectionFilter, useDataTable } from '@/composables/useDataTable';
import {
    index as eventsIndex,
    show as showEvent,
    store as storeEvent,
} from '@/routes/admin/events';

type EventRow = {
    id: string;
    name: string;
    slug: string;
    state: string;
    startsAt: string;
    timezone: string;
};

interface PaginatedEvents {
    data: EventRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    events: PaginatedEvents;
    brands: { id: string; name: string }[];
    filters: DataTableFilters & { brand: string | null };
}>();

const selection = useConsoleSelection();

// `seedSelectionFilter` carries a cleared filter forward as `all` — but only
// when the shell has a pick to have cleared.
const { filters, setSearch, setFilter, toggleSort, setPerPage, setPage } =
    useDataTable(() => eventsIndex().url, {
        ...props.filters,
        brand: seedSelectionFilter(
            props.filters.brand,
            selection.value?.brandId,
        ),
    });

/**
 * Brand only: this list is what the console's event selector points into, so
 * the event half of the selection deliberately does not apply. `clearValue:
 * 'all'` makes "All brands" survive the next visit.
 */
const filterDefs = computed<DataTableFilterDef[]>(() => [
    {
        name: 'brand',
        label: 'Brands',
        value: props.filters.brand,
        options: props.brands.map((brand) => ({
            value: brand.id,
            label: brand.name,
        })),
        clearValue: 'all',
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
    currentPage: props.events.current_page,
    lastPage: props.events.last_page,
    total: props.events.total,
    from: props.events.from,
    to: props.events.to,
}));

const stateClasses: Record<string, string> = {
    draft: 'bg-subtle text-text-muted',
    announced: 'bg-accent/15 text-accent',
    published: 'bg-success/15 text-success',
    running: 'bg-success/15 text-success',
    concluded: 'bg-subtle text-text-muted',
    archived: 'bg-subtle text-text-muted',
    cancelled: 'bg-danger/15 text-danger',
};

const columns: ColumnDef<EventRow>[] = [
    {
        accessorKey: 'name',
        header: 'Name',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                Link,
                {
                    href: showEvent(row.original.id).url,
                    class: 'font-medium hover:text-accent',
                },
                () => row.getValue<string>('name'),
            ),
    },
    {
        accessorKey: 'slug',
        header: 'Slug',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                row.getValue<string>('slug'),
            ),
    },
    {
        accessorKey: 'state',
        header: 'State',
        enableSorting: true,
        cell: ({ row }) => {
            const state = row.getValue<string>('state');

            return h(
                'span',
                {
                    class: `inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${stateClasses[state] ?? 'bg-subtle text-text-muted'}`,
                },
                state,
            );
        },
    },
    {
        accessorKey: 'starts_at',
        accessorFn: (row) => row.startsAt,
        header: 'Starts',
        enableSorting: true,
        cell: ({ row }) =>
            h('span', { class: 'text-text-muted' }, [
                `${row.original.startsAt} `,
                h('span', { class: 'text-xs' }, `(${row.original.timezone})`),
            ]),
    },
];

const open = ref(false);

const form = useForm({
    brand_id: props.brands[0]?.id ?? '',
    name: '',
    slug: '',
    description: null as string | null,
    startsAt: '',
    endsAt: '',
    timezone: 'Europe/Berlin',
    visibility: 'public',
    reentryPolicy: 'unlimited',
    predecessorEventId: null as string | null,
    days: [] as { date: string; label: string | null }[],
});

function submit(): void {
    form.post(storeEvent().url, {
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
</script>

<template>
    <Head title="Events" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Events"
            description="Every event across its lifecycle"
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            :filters="filterDefs"
            search-placeholder="Search events…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
            @update:filter="setFilter"
        >
            <template #actions>
                <Dialog v-model:open="open">
                    <DialogTrigger as-child>
                        <Button>
                            <Plus class="size-4" />
                            New event
                        </Button>
                    </DialogTrigger>
                    <DialogContent class="max-h-[85vh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>Create event</DialogTitle>
                        </DialogHeader>
                        <form
                            class="flex flex-col gap-4"
                            @submit.prevent="submit"
                        >
                            <div class="grid gap-2">
                                <Label for="event-brand">Brand</Label>
                                <Select v-model="form.brand_id">
                                    <SelectTrigger id="event-brand">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="brand in props.brands"
                                            :key="brand.id"
                                            :value="brand.id"
                                        >
                                            {{ brand.name }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError :message="form.errors.brand_id" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="event-name">Name</Label>
                                <Input id="event-name" v-model="form.name" />
                                <InputError :message="form.errors.name" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="event-slug">Slug</Label>
                                <Input id="event-slug" v-model="form.slug" />
                                <InputError :message="form.errors.slug" />
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div class="grid gap-2">
                                    <Label for="event-starts"
                                        >Starts (local)</Label
                                    >
                                    <Input
                                        id="event-starts"
                                        v-model="form.startsAt"
                                        placeholder="2027-05-14 18:00:00"
                                    />
                                    <InputError
                                        :message="form.errors.startsAt"
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="event-ends">Ends (local)</Label>
                                    <Input
                                        id="event-ends"
                                        v-model="form.endsAt"
                                        placeholder="2027-05-16 14:00:00"
                                    />
                                    <InputError :message="form.errors.endsAt" />
                                </div>
                            </div>
                            <div class="grid gap-2">
                                <Label for="event-tz">Timezone</Label>
                                <Input id="event-tz" v-model="form.timezone" />
                                <InputError :message="form.errors.timezone" />
                            </div>
                            <Button type="submit" :disabled="form.processing">
                                Create
                            </Button>
                        </form>
                    </DialogContent>
                </Dialog>
            </template>
        </DataTableToolbar>

        <DataTable
            data-test="events-table"
            :columns="columns"
            :data="props.events.data"
            :sorting="sorting"
            :pagination="pagination"
            items-label="events"
            empty-message="No events yet."
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

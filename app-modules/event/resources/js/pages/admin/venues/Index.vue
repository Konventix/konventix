<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import { computed, h, ref } from 'vue';
import DataTable from '@/components/admin/DataTable.vue';
import DataTableToolbar from '@/components/admin/DataTableToolbar.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { DataTableFilters } from '@/composables/useDataTable';
import { useDataTable } from '@/composables/useDataTable';
import {
    index as venuesIndex,
    store as storeVenue,
    update as updateVenue,
} from '@/routes/admin/venues';

type VenueRow = {
    id: string;
    name: string;
    addressLine1: string;
    addressLine2: string | null;
    postalCode: string;
    city: string;
    countryCode: string;
    accessNotes: string | null;
};

interface PaginatedVenues {
    data: VenueRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    venues: PaginatedVenues;
    brands: { id: string; name: string }[];
    filters: DataTableFilters;
}>();

const { filters, setSearch, toggleSort, setPerPage, setPage } = useDataTable(
    () => venuesIndex().url,
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
    currentPage: props.venues.current_page,
    lastPage: props.venues.last_page,
    total: props.venues.total,
    from: props.venues.from,
    to: props.venues.to,
}));

const open = ref(false);
const editing = ref<string | null>(null);

const form = useForm({
    brand_id: props.brands[0]?.id ?? '',
    name: '',
    addressLine1: '',
    addressLine2: null as string | null,
    postalCode: '',
    city: '',
    countryCode: 'DE',
    latitude: null as number | null,
    longitude: null as number | null,
    accessNotes: null as string | null,
    openingTimes: [] as unknown[],
});

function openCreate(): void {
    editing.value = null;
    form.reset();
    open.value = true;
}

function openEdit(row: VenueRow): void {
    editing.value = row.id;
    form.name = row.name;
    form.addressLine1 = row.addressLine1;
    form.addressLine2 = row.addressLine2;
    form.postalCode = row.postalCode;
    form.city = row.city;
    form.countryCode = row.countryCode;
    form.accessNotes = row.accessNotes;
    open.value = true;
}

function submit(): void {
    const options = {
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    };

    if (editing.value === null) {
        form.post(storeVenue().url, options);
    } else {
        form.put(updateVenue(editing.value).url, options);
    }
}

const columns: ColumnDef<VenueRow>[] = [
    {
        accessorKey: 'name',
        header: 'Name',
        enableSorting: true,
        cell: ({ row }) =>
            h('span', { class: 'font-medium' }, row.getValue<string>('name')),
    },
    {
        id: 'address',
        header: 'Address',
        enableSorting: false,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                `${row.original.addressLine1}, ${row.original.postalCode} ${row.original.city}`,
            ),
    },
    {
        accessorKey: 'city',
        header: 'City',
        enableSorting: true,
        cell: ({ row }) =>
            h('span', { class: 'text-text-muted' }, row.getValue<string>('city')),
    },
    {
        id: 'actions',
        header: '',
        enableSorting: false,
        cell: ({ row }) =>
            h(
                Button,
                {
                    variant: 'ghost',
                    size: 'sm',
                    onClick: () => openEdit(row.original),
                },
                () => h(Pencil, { class: 'size-4' }),
            ),
    },
];
</script>

<template>
    <Head title="Venues" />

    <div class="flex flex-col gap-6">
        <h1 class="text-2xl font-semibold">Venues</h1>

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            search-placeholder="Search venues…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
        >
            <template #actions>
                <Button @click="openCreate">
                    <Plus class="size-4" />
                    New venue
                </Button>
            </template>
        </DataTableToolbar>

        <DataTable
            data-test="venues-table"
            :columns="columns"
            :data="props.venues.data"
            :sorting="sorting"
            :pagination="pagination"
            items-label="venues"
            empty-message="No venues yet."
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[85vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        {{ editing === null ? 'Create venue' : 'Edit venue' }}
                    </DialogTitle>
                </DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div v-if="editing === null" class="grid gap-2">
                        <Label for="venue-brand">Brand</Label>
                        <select
                            id="venue-brand"
                            v-model="form.brand_id"
                            class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                        >
                            <option
                                v-for="brand in props.brands"
                                :key="brand.id"
                                :value="brand.id"
                            >
                                {{ brand.name }}
                            </option>
                        </select>
                        <InputError :message="form.errors.brand_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="venue-name">Name</Label>
                        <Input id="venue-name" v-model="form.name" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="venue-address1">Street and number</Label>
                        <Input id="venue-address1" v-model="form.addressLine1" />
                        <InputError :message="form.errors.addressLine1" />
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <div class="grid gap-2">
                            <Label for="venue-postal">Postal code</Label>
                            <Input id="venue-postal" v-model="form.postalCode" />
                            <InputError :message="form.errors.postalCode" />
                        </div>
                        <div class="col-span-2 grid gap-2">
                            <Label for="venue-city">City</Label>
                            <Input id="venue-city" v-model="form.city" />
                            <InputError :message="form.errors.city" />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="venue-country">Country code</Label>
                        <Input
                            id="venue-country"
                            v-model="form.countryCode"
                            maxlength="2"
                            class="w-24"
                        />
                        <InputError :message="form.errors.countryCode" />
                    </div>
                    <Button type="submit" :disabled="form.processing">
                        {{ editing === null ? 'Create' : 'Save' }}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>

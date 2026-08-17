<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import { computed, h, ref, watch } from 'vue';
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
import type { DataTableFilters } from '@/composables/useDataTable';
import { useDataTable } from '@/composables/useDataTable';
import {
    edit as editType,
    index as typesIndex,
    store as storeType,
} from '@/routes/admin/registration-types';

/**
 * The offer, listed by its axes (ADR-0005): four independent settings that
 * turn one model into a visitor ticket, an exhibitor package, a speaker
 * invitation or a crew slot. The list shows all four, because a type's name
 * says nothing about how it behaves.
 *
 * Creation asks only for the identity of the type — event, name and the axes.
 * Grants, price and quotas are the editor's job on the next screen, where
 * there is room to explain what they mean.
 */
type TypeRow = {
    id: string;
    name: string;
    eventName: string;
    visibility: string;
    approval: string;
    settlement: string;
    confirmationGate: string;
    price: string | null;
    registrations: number;
};

interface PaginatedTypes {
    data: TypeRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    types: PaginatedTypes;
    brands: { id: string; name: string }[];
    events: { id: string; name: string; brandId: string }[];
    options: Record<string, string[]>;
    filters: DataTableFilters;
}>();

const { filters, setSearch, toggleSort, setPerPage, setPage } = useDataTable(
    () => typesIndex().url,
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
    currentPage: props.types.current_page,
    lastPage: props.types.last_page,
    total: props.types.total,
    from: props.types.from,
    to: props.types.to,
}));

const columns: ColumnDef<TypeRow>[] = [
    {
        accessorKey: 'name',
        header: 'Name',
        enableSorting: true,
        cell: ({ row }) =>
            h('div', { class: 'flex flex-col' }, [
                h(
                    Link,
                    {
                        href: editType(row.original.id).url,
                        class: 'font-medium hover:text-accent',
                    },
                    () => row.original.name,
                ),
                h(
                    'span',
                    { class: 'text-xs text-text-muted' },
                    row.original.eventName,
                ),
            ]),
    },
    {
        id: 'axes',
        header: 'Axes',
        cell: ({ row }) =>
            h(
                'div',
                { class: 'flex flex-wrap gap-1' },
                [
                    row.original.visibility,
                    row.original.approval === 'required'
                        ? 'approval'
                        : 'no approval',
                    row.original.settlement,
                    row.original.confirmationGate,
                ].map((label) =>
                    h(
                        'span',
                        {
                            class: 'rounded-full bg-subtle px-2 py-0.5 text-xs text-text-muted',
                        },
                        label,
                    ),
                ),
            ),
    },
    {
        accessorKey: 'settlement',
        header: 'Price',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                row.original.price === null ? '—' : `${row.original.price} net`,
            ),
    },
    {
        id: 'registrations',
        header: 'Registrations',
        cell: ({ row }) =>
            h('div', { class: 'flex flex-col' }, [
                h('span', {}, String(row.original.registrations)),
                row.original.registrations > 0
                    ? h(
                          'span',
                          { class: 'text-xs text-warning' },
                          'terms frozen',
                      )
                    : null,
            ]),
    },
];

const open = ref(false);

const form = useForm({
    brand_id: props.brands[0]?.id ?? '',
    eventId: '',
    name: '',
    visibility: 'public',
    approval: 'none',
    settlement: 'free',
    confirmationGate: 'on_submit',
    termsDays: 14,
    grants: [] as Konventix.Registration.Data.GrantData[],
    priceDeclaration: null as {
        unitAmountNet: string;
        taxClass: string;
        display: string;
    } | null,
});

/**
 * A priced settlement needs a price declaration (ADR-0017), and the server
 * refuses the pair without one — so the fields appear here rather than
 * letting someone save into an error they cannot fix on this screen.
 */
const needsPrice = computed(
    () => form.settlement === 'instant' || form.settlement === 'invoice',
);

watch(needsPrice, (priced) => {
    form.priceDeclaration = priced
        ? { unitAmountNet: '0.00', taxClass: 'standard', display: 'net' }
        : null;
});

/** Only the chosen brand's events — a type belongs to one event of one brand. */
const eventsForBrand = computed(() =>
    props.events.filter((event) => event.brandId === form.brand_id),
);

function submit(): void {
    form.post(storeType().url, {
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
</script>

<template>
    <Head title="Registration types" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Registration types"
            description="The offer: visibility, approval, settlement and confirmation gate"
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            search-placeholder="Search types…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
        >
            <template #actions>
                <Dialog v-model:open="open">
                    <DialogTrigger as-child>
                        <Button>
                            <Plus class="size-4" />
                            New type
                        </Button>
                    </DialogTrigger>
                    <DialogContent class="max-h-[85vh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>Create registration type</DialogTitle>
                        </DialogHeader>
                        <form
                            class="flex flex-col gap-4"
                            @submit.prevent="submit"
                        >
                            <div class="grid gap-2">
                                <Label for="type-brand">Brand</Label>
                                <select
                                    id="type-brand"
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
                                <Label for="type-event">Event</Label>
                                <select
                                    id="type-event"
                                    v-model="form.eventId"
                                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                                >
                                    <option value="" disabled>
                                        Choose an event
                                    </option>
                                    <option
                                        v-for="event in eventsForBrand"
                                        :key="event.id"
                                        :value="event.id"
                                    >
                                        {{ event.name }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.eventId" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="type-name">Name</Label>
                                <Input id="type-name" v-model="form.name" />
                                <InputError :message="form.errors.name" />
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div class="grid gap-2">
                                    <Label for="type-visibility">
                                        Visibility
                                    </Label>
                                    <select
                                        id="type-visibility"
                                        v-model="form.visibility"
                                        class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                                    >
                                        <option
                                            v-for="value in props.options
                                                .visibility"
                                            :key="value"
                                            :value="value"
                                        >
                                            {{ value }}
                                        </option>
                                    </select>
                                    <InputError
                                        :message="form.errors.visibility"
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="type-approval">Approval</Label>
                                    <select
                                        id="type-approval"
                                        v-model="form.approval"
                                        class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                                    >
                                        <option
                                            v-for="value in props.options
                                                .approval"
                                            :key="value"
                                            :value="value"
                                        >
                                            {{ value }}
                                        </option>
                                    </select>
                                    <InputError
                                        :message="form.errors.approval"
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="type-settlement">
                                        Settlement
                                    </Label>
                                    <select
                                        id="type-settlement"
                                        v-model="form.settlement"
                                        class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                                    >
                                        <option
                                            v-for="value in props.options
                                                .settlement"
                                            :key="value"
                                            :value="value"
                                        >
                                            {{ value }}
                                        </option>
                                    </select>
                                    <InputError
                                        :message="form.errors.settlement"
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="type-gate">
                                        Confirmation gate
                                    </Label>
                                    <select
                                        id="type-gate"
                                        v-model="form.confirmationGate"
                                        class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                                    >
                                        <option
                                            v-for="value in props.options
                                                .confirmationGate"
                                            :key="value"
                                            :value="value"
                                        >
                                            {{ value }}
                                        </option>
                                    </select>
                                    <InputError
                                        :message="form.errors.confirmationGate"
                                    />
                                </div>
                            </div>

                            <div
                                v-if="form.priceDeclaration"
                                class="grid grid-cols-3 gap-4 rounded-md border border-border p-3"
                            >
                                <div class="grid gap-2">
                                    <Label for="type-price">Net price</Label>
                                    <Input
                                        id="type-price"
                                        v-model="
                                            form.priceDeclaration.unitAmountNet
                                        "
                                        placeholder="250.00"
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="type-tax">Tax class</Label>
                                    <select
                                        id="type-tax"
                                        v-model="form.priceDeclaration.taxClass"
                                        class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                                    >
                                        <option
                                            v-for="value in props.options
                                                .taxClass"
                                            :key="value"
                                            :value="value"
                                        >
                                            {{ value }}
                                        </option>
                                    </select>
                                </div>
                                <div class="grid gap-2">
                                    <Label for="type-display">Display</Label>
                                    <select
                                        id="type-display"
                                        v-model="form.priceDeclaration.display"
                                        class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                                    >
                                        <option
                                            v-for="value in props.options
                                                .priceDisplay"
                                            :key="value"
                                            :value="value"
                                        >
                                            {{ value }}
                                        </option>
                                    </select>
                                </div>
                                <InputError
                                    class="col-span-3"
                                    :message="form.errors.priceDeclaration"
                                />
                            </div>

                            <p class="text-sm text-text-muted">
                                Grants and quotas come next, on the type's own
                                screen.
                            </p>

                            <Button type="submit" :disabled="form.processing">
                                Create
                            </Button>
                        </form>
                    </DialogContent>
                </Dialog>
            </template>
        </DataTableToolbar>

        <DataTable
            data-test="registration-types-table"
            :columns="columns"
            :data="props.types.data"
            :sorting="sorting"
            :pagination="pagination"
            items-label="types"
            empty-message="No registration types yet."
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

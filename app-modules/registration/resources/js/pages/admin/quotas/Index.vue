<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
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
import type { DataTableFilters } from '@/composables/useDataTable';
import { useDataTable } from '@/composables/useDataTable';
import {
    index as quotasIndex,
    store as storeQuota,
    update as updateQuota,
} from '@/routes/admin/quotas';

/**
 * Quotas are a standalone entity shared by several registration types
 * (ADR-0005), so they get their own screen and the list names the types
 * drawing on each one — "how many places are left" only means something
 * together with "for whom".
 *
 * `consumed` is never editable here: the registration Actions move it under a
 * row lock. Capacity is the lever an organizer has, and the server refuses to
 * cut it below what is already taken.
 *
 * `resourceTypeId` (design §12b, #282) links a quota to a Resources seat
 * category as its ceiling: blocking or reserving a place in that category
 * now consumes a unit here exactly as a sale would. `categoriesByBrand`
 * comes from Resources' `registration.quota-form.categories` slot — this
 * page never queries `resource_types` itself (ADR-0004).
 */
type QuotaRow = {
    id: string;
    name: string;
    brandId: string;
    brandName: string;
    resourceTypeId: string | null;
    categoryName: string | null;
    capacity: number;
    consumed: number;
    remaining: number;
    typeNames: string[];
};

type CategoryOption = {
    id: string;
    name: string;
    eventName: string | null;
};

interface PaginatedQuotas {
    data: QuotaRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    quotas: PaginatedQuotas;
    brands: { id: string; name: string }[];
    categoriesByBrand: Record<string, CategoryOption[]>;
    filters: DataTableFilters;
}>();

function categoryLabel(category: CategoryOption): string {
    return category.eventName
        ? `${category.name} (${category.eventName})`
        : category.name;
}

const { filters, setSearch, toggleSort, setPerPage, setPage } = useDataTable(
    () => quotasIndex().url,
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
    currentPage: props.quotas.current_page,
    lastPage: props.quotas.last_page,
    total: props.quotas.total,
    from: props.quotas.from,
    to: props.quotas.to,
}));

const createOpen = ref(false);

const createForm = useForm({
    brand_id: props.brands[0]?.id ?? '',
    name: '',
    capacity: 100,
    resourceTypeId: null as string | null,
});

const createCategories = computed<CategoryOption[]>(
    () => props.categoriesByBrand[createForm.brand_id] ?? [],
);

function create(): void {
    createForm.post(storeQuota().url, {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset();
            createOpen.value = false;
        },
    });
}

const editing = ref<QuotaRow | null>(null);

const editForm = useForm({
    name: '',
    capacity: 0,
    resourceTypeId: null as string | null,
});

const editCategories = computed<CategoryOption[]>(() =>
    editing.value === null
        ? []
        : (props.categoriesByBrand[editing.value.brandId] ?? []),
);

function startEdit(quota: QuotaRow): void {
    editing.value = quota;
    editForm.defaults({
        name: quota.name,
        capacity: quota.capacity,
        resourceTypeId: quota.resourceTypeId,
    });
    editForm.reset();
    editForm.clearErrors();
}

function saveEdit(): void {
    if (editing.value === null) {
        return;
    }

    editForm.put(updateQuota(editing.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
        },
    });
}

const columns: ColumnDef<QuotaRow>[] = [
    {
        accessorKey: 'name',
        header: 'Name',
        enableSorting: true,
        cell: ({ row }) =>
            h('div', { class: 'flex flex-col' }, [
                h('span', { class: 'font-medium' }, row.original.name),
                h(
                    'span',
                    { class: 'text-xs text-text-muted' },
                    row.original.typeNames.length > 0
                        ? row.original.typeNames.join(', ')
                        : 'No type draws on this quota',
                ),
            ]),
    },
    {
        id: 'brand',
        header: 'Brand',
        cell: ({ row }) =>
            h('span', { class: 'text-text-muted' }, row.original.brandName),
    },
    {
        id: 'category',
        header: 'Category',
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                row.original.categoryName ?? '—',
            ),
    },
    {
        accessorKey: 'capacity',
        header: 'Capacity',
        enableSorting: true,
        cell: ({ row }) => String(row.original.capacity),
    },
    {
        accessorKey: 'consumed',
        header: 'Taken',
        enableSorting: true,
        cell: ({ row }) => String(row.original.consumed),
    },
    {
        id: 'remaining',
        header: 'Left',
        cell: ({ row }) =>
            h(
                'span',
                {
                    class:
                        row.original.remaining === 0
                            ? 'font-medium text-danger'
                            : 'font-medium text-success',
                },
                String(row.original.remaining),
            ),
    },
    {
        id: 'actions',
        header: '',
        cell: ({ row }) =>
            h('div', { class: 'flex justify-end' }, [
                h(
                    Button,
                    {
                        size: 'sm',
                        variant: 'outline',
                        onClick: () => startEdit(row.original),
                    },
                    () => [h(Pencil, { class: 'size-3.5' }), 'Edit'],
                ),
            ]),
    },
];
</script>

<template>
    <Head title="Quotas" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Quotas"
            description="Shared capacity pools the registration types draw on"
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            search-placeholder="Search quotas…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
        >
            <template #actions>
                <Dialog v-model:open="createOpen">
                    <DialogTrigger as-child>
                        <Button>
                            <Plus class="size-4" />
                            New quota
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Create quota</DialogTitle>
                        </DialogHeader>
                        <form
                            class="flex flex-col gap-4"
                            @submit.prevent="create"
                        >
                            <div class="grid gap-2">
                                <Label for="quota-brand">Brand</Label>
                                <select
                                    id="quota-brand"
                                    v-model="createForm.brand_id"
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
                                <InputError
                                    :message="createForm.errors.brand_id"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="quota-name">Name</Label>
                                <Input
                                    id="quota-name"
                                    v-model="createForm.name"
                                />
                                <InputError :message="createForm.errors.name" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="quota-category">Category</Label>
                                <select
                                    id="quota-category"
                                    v-model="createForm.resourceTypeId"
                                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                                >
                                    <option :value="null">
                                        No category — a plain quota
                                    </option>
                                    <option
                                        v-for="category in createCategories"
                                        :key="category.id"
                                        :value="category.id"
                                    >
                                        {{ categoryLabel(category) }}
                                    </option>
                                </select>
                                <InputError
                                    :message="createForm.errors.resourceTypeId"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="quota-capacity">Capacity</Label>
                                <Input
                                    id="quota-capacity"
                                    v-model.number="createForm.capacity"
                                    type="number"
                                    min="0"
                                />
                                <InputError
                                    :message="createForm.errors.capacity"
                                />
                            </div>
                            <Button
                                type="submit"
                                :disabled="createForm.processing"
                            >
                                Create
                            </Button>
                        </form>
                    </DialogContent>
                </Dialog>
            </template>
        </DataTableToolbar>

        <DataTable
            data-test="quotas-table"
            :columns="columns"
            :data="props.quotas.data"
            :sorting="sorting"
            :pagination="pagination"
            items-label="quotas"
            empty-message="No quotas yet."
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />

        <Dialog
            :open="editing !== null"
            @update:open="(open) => !open && (editing = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Edit {{ editing?.name }}</DialogTitle>
                </DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="saveEdit">
                    <div class="grid gap-2">
                        <Label for="edit-quota-name">Name</Label>
                        <Input id="edit-quota-name" v-model="editForm.name" />
                        <InputError :message="editForm.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="edit-quota-category">Category</Label>
                        <select
                            id="edit-quota-category"
                            v-model="editForm.resourceTypeId"
                            class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                        >
                            <option :value="null">
                                No category — a plain quota
                            </option>
                            <option
                                v-for="category in editCategories"
                                :key="category.id"
                                :value="category.id"
                            >
                                {{ categoryLabel(category) }}
                            </option>
                        </select>
                        <InputError :message="editForm.errors.resourceTypeId" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="edit-quota-capacity">Capacity</Label>
                        <Input
                            id="edit-quota-capacity"
                            v-model.number="editForm.capacity"
                            type="number"
                            :min="editing?.consumed ?? 0"
                        />
                        <p class="text-xs text-text-muted">
                            {{ editing?.consumed ?? 0 }} places are already
                            taken and cannot be given back by lowering this.
                        </p>
                        <InputError :message="editForm.errors.capacity" />
                    </div>
                    <Button type="submit" :disabled="editForm.processing">
                        Save
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>

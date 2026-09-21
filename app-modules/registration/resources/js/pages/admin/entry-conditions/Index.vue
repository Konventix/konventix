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
import type {
    DataTableFilterDef,
    DataTableFilters,
} from '@/composables/useDataTable';
import { useDataTable } from '@/composables/useDataTable';
import {
    edit as editCondition,
    index as conditionsIndex,
    store as storeCondition,
} from '@/routes/admin/entry-conditions';

/**
 * What a reduced fare rests on (ADR-0066 §9.1): the catalogue of conditions
 * a brand may hang on its registration types.
 *
 * Creation asks for the identity of the condition — brand, key, name and the
 * sentence. Everything that needs explaining (the document hint, how it is
 * verified, the live preview of the checkbox) is the editor's job on the
 * next screen, the same split the registration types make.
 *
 * There is no delete. A condition is archived, and archived rows are hidden
 * unless asked for: every declaration that ever named one stays readable.
 */
type ConditionRow = {
    id: string;
    brandName: string;
    key: string;
    name: string;
    assertionText: string;
    verification: string;
    archived: boolean;
    types: number;
};

interface PaginatedConditions {
    data: ConditionRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    conditions: PaginatedConditions;
    brands: { id: string; name: string }[];
    options: Record<string, string[]>;
    filters: DataTableFilters & {
        brand: string | null;
        archived: string | null;
    };
}>();

const { filters, setSearch, setFilter, toggleSort, setPerPage, setPage } =
    useDataTable(() => conditionsIndex().url, props.filters);

const filterDefs = computed<DataTableFilterDef[]>(() => [
    {
        name: 'brand',
        label: 'Brands',
        value: props.filters.brand,
        options: props.brands.map((brand) => ({
            value: brand.id,
            label: brand.name,
        })),
    },
    {
        name: 'archived',
        label: 'Archived',
        value: props.filters.archived,
        options: [{ value: 'yes', label: 'Show archived' }],
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
    currentPage: props.conditions.current_page,
    lastPage: props.conditions.last_page,
    total: props.conditions.total,
    from: props.conditions.from,
    to: props.conditions.to,
}));

const columns: ColumnDef<ConditionRow>[] = [
    {
        accessorKey: 'name',
        header: 'Name',
        enableSorting: true,
        cell: ({ row }) =>
            h('div', { class: 'flex flex-col' }, [
                h(
                    Link,
                    {
                        href: editCondition(row.original.id).url,
                        class: 'font-medium hover:text-accent',
                    },
                    () => row.original.name,
                ),
                h(
                    'span',
                    { class: 'text-xs text-text-muted' },
                    row.original.brandName,
                ),
            ]),
    },
    {
        accessorKey: 'key',
        header: 'Key',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                'code',
                { class: 'rounded bg-subtle px-1.5 py-0.5 text-xs' },
                row.original.key,
            ),
    },
    {
        id: 'assertionText',
        header: 'What the registrant confirms',
        enableSorting: false,
        cell: ({ row }) =>
            h('span', { class: 'text-text-muted' }, row.original.assertionText),
    },
    {
        accessorKey: 'verification',
        header: 'Verification',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                'span',
                {
                    class: 'rounded-full bg-subtle px-2 py-0.5 text-xs text-text-muted',
                },
                row.original.verification,
            ),
    },
    {
        id: 'types',
        header: 'On types',
        enableSorting: false,
        cell: ({ row }) =>
            h('div', { class: 'flex flex-col' }, [
                h(
                    'span',
                    { class: 'tabular-nums' },
                    String(row.original.types),
                ),
                row.original.archived
                    ? h('span', { class: 'text-xs text-warning' }, 'archived')
                    : null,
            ]),
    },
];

const open = ref(false);

const form = useForm({
    brand_id: props.brands[0]?.id ?? '',
    eventId: null as string | null,
    key: '',
    name: '',
    description: null as string | null,
    assertionText: '',
    documentHint: null as string | null,
    verification: 'self_declared',
});

function submit(): void {
    form.post(storeCondition().url, {
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
</script>

<template>
    <Head title="Entry conditions" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Entry conditions"
            description="What a reduced fare rests on: the sentence a registrant confirms, and the document the door asks for"
        />

        <p class="text-sm text-text-muted">
            A condition is written once per brand and reused on as many
            registration types as you like. It is archived, never deleted —
            every declaration that ever named it stays readable.
        </p>

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            :filters="filterDefs"
            search-placeholder="Search name or key…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
            @update:filter="setFilter"
        >
            <template #actions>
                <Dialog v-model:open="open">
                    <DialogTrigger as-child>
                        <Button>
                            <Plus class="size-4" />
                            New condition
                        </Button>
                    </DialogTrigger>
                    <DialogContent class="max-h-[85vh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>Create entry condition</DialogTitle>
                        </DialogHeader>
                        <form
                            class="flex flex-col gap-4"
                            @submit.prevent="submit"
                        >
                            <div class="grid gap-2">
                                <Label for="condition-brand">Brand</Label>
                                <select
                                    id="condition-brand"
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
                                <Label for="condition-key">Key</Label>
                                <Input id="condition-key" v-model="form.key" />
                                <p class="text-xs text-text-muted">
                                    Lower case, words joined by hyphens
                                    (<code>student</code>). It travels to the
                                    door and onto the ticket, so keep it stable.
                                </p>
                                <InputError :message="form.errors.key" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="condition-name">Name</Label>
                                <Input
                                    id="condition-name"
                                    v-model="form.name"
                                    placeholder="Student ticket"
                                />
                                <InputError :message="form.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="condition-assertion">
                                    What the registrant confirms
                                </Label>
                                <textarea
                                    id="condition-assertion"
                                    v-model="form.assertionText"
                                    rows="3"
                                    class="rounded-md border border-border bg-surface px-3 py-2 text-sm"
                                    placeholder="I confirm that I am enrolled as a student and will present a valid student ID at the door."
                                />
                                <InputError
                                    :message="form.errors.assertionText"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="condition-verification">
                                    Verification
                                </Label>
                                <select
                                    id="condition-verification"
                                    v-model="form.verification"
                                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                                >
                                    <option
                                        v-for="value in props.options
                                            .verification"
                                        :key="value"
                                        :value="value"
                                    >
                                        {{ value }}
                                    </option>
                                </select>
                                <InputError
                                    :message="form.errors.verification"
                                />
                            </div>

                            <div class="flex justify-end">
                                <Button
                                    type="submit"
                                    :disabled="form.processing"
                                >
                                    Create
                                </Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
            </template>
        </DataTableToolbar>

        <DataTable
            data-test="entry-conditions-table"
            :columns="columns"
            :data="props.conditions.data"
            :sorting="sorting"
            :pagination="pagination"
            items-label="entry conditions"
            empty-message="No entry conditions yet."
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

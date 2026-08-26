<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import type { SortingState } from '@tanstack/vue-table';
import { computed, ref } from 'vue';
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
import { useConsoleSelection } from '@/composables/useConsoleSelection';
import type {
    DataTableFilterDef,
    DataTableFilters,
} from '@/composables/useDataTable';
import { seedSelectionFilter, useDataTable } from '@/composables/useDataTable';
import {
    edit as editPage,
    index as pagesIndex,
    store as createPage,
} from '@/routes/admin/pages';
import { columns } from './columns';
import type { PageRow } from './columns';

interface PaginatedPages {
    data: PageRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    pages: PaginatedPages;
    brands: { id: string; name: string }[];
    filters: DataTableFilters & { brand: string | null };
}>();

const selection = useConsoleSelection();

// `seedSelectionFilter` carries a cleared filter forward as `all` — but only
// when the shell has a pick to have cleared.
const { filters, setSearch, setFilter, toggleSort, setPerPage, setPage } =
    useDataTable(() => pagesIndex().url, {
        ...props.filters,
        brand: seedSelectionFilter(
            props.filters.brand,
            selection.value?.brandId,
        ),
    });

const filterDefs = computed<DataTableFilterDef[]>(() => [
    {
        name: 'brand',
        label: 'Brand',
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
    currentPage: props.pages.current_page,
    lastPage: props.pages.last_page,
    total: props.pages.total,
    from: props.pages.from,
    to: props.pages.to,
}));

const open = ref(false);

const form = useForm({
    title: '',
    slug: '',
    brandId: null as string | null,
});

function submit(): void {
    form.post(createPage().url, {
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
</script>

<template>
    <Head title="Pages" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Pages"
            description="A brand's static pages, composed of blocks"
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            :filters="filterDefs"
            search-placeholder="Search title or slug…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
            @update:filter="setFilter"
        >
            <template #actions>
                <Dialog v-model:open="open">
                    <DialogTrigger as-child>
                        <Button>
                            <Plus class="size-4" />
                            New page
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Create page</DialogTitle>
                        </DialogHeader>
                        <form
                            class="flex flex-col gap-4"
                            @submit.prevent="submit"
                        >
                            <div v-if="!selection?.brandId" class="grid gap-2">
                                <Label for="page-brand">Brand</Label>
                                <select
                                    id="page-brand"
                                    class="rounded-md border border-border bg-surface px-3 py-2 text-sm"
                                    :value="form.brandId ?? ''"
                                    @change="
                                        form.brandId =
                                            ($event.target as HTMLSelectElement)
                                                .value || null
                                    "
                                >
                                    <option value="" disabled>
                                        Choose a brand
                                    </option>
                                    <option
                                        v-for="brand in props.brands"
                                        :key="brand.id"
                                        :value="brand.id"
                                    >
                                        {{ brand.name }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.brandId" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="page-title">Title</Label>
                                <Input id="page-title" v-model="form.title" />
                                <InputError :message="form.errors.title" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="page-slug">Slug</Label>
                                <Input id="page-slug" v-model="form.slug" />
                                <p class="text-xs text-text-muted">
                                    `home` is the brand's landing page; anything
                                    else answers at /pages/&lt;slug&gt;.
                                </p>
                                <InputError :message="form.errors.slug" />
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
            data-test="pages-table"
            :columns="columns"
            :data="pages.data"
            :sorting="sorting"
            :row-count="pages.total"
            :pagination="pagination"
            :get-row-id="(row: PageRow) => row.id"
            items-label="pages"
            empty-message="No pages found."
            row-clickable
            @row-click="(row: PageRow) => router.visit(editPage(row.id))"
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

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
    DialogDescription,
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
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import {
    edit as editProduct,
    index as productsIndex,
    store as createProduct,
} from '@/routes/admin/products';
import { columns } from './columns';
import type { ProductRow } from './columns';

interface PaginatedProducts {
    data: ProductRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    products: PaginatedProducts;
    brands: { id: string; name: string }[];
    filters: DataTableFilters & { brand: string | null };
}>();

const selection = useConsoleSelection();

// `seedSelectionFilter` carries a cleared filter forward as `all` — but only
// when the shell has a pick to have cleared.
const { filters, setSearch, setFilter, toggleSort, setPerPage, setPage } =
    useDataTable(() => productsIndex().url, {
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
    currentPage: props.products.current_page,
    lastPage: props.products.last_page,
    total: props.products.total,
    from: props.products.from,
    to: props.products.to,
}));

const open = ref(false);

/**
 * `SaveProductData` carries no `brandId` — creation resolves the brand from
 * the console's own selection, so the dialog collects just enough to get a
 * product on the shelf; everything else (image, event, tax class, variants)
 * is filled in on the editor this redirects to.
 */
const form = useForm<{
    name: string;
    slug: string;
    description: string | null;
    eventId: string | null;
    imageMediaId: string | null;
    taxClass: Konventix.Commerce.Enums.TaxClass;
    unitAmountNet: string;
    priceDisplay: Konventix.Commerce.Enums.PriceDisplay;
    published: boolean;
    stockCapacity: number;
    variants: Konventix.Commerce.Data.ProductVariantData[];
}>({
    name: '',
    slug: '',
    description: null,
    eventId: null,
    imageMediaId: null,
    taxClass: 'standard',
    unitAmountNet: '0.00',
    priceDisplay: 'gross',
    published: false,
    stockCapacity: 0,
    variants: [],
});

useUnsavedChanges(() => open.value && form.isDirty);

function submit(): void {
    form.post(createProduct().url, {
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}

// `brand` is not one of this form's own fields — `SaveProductData` carries
// no `brandId`, so the server reports "pick a brand first" under a key the
// form's own type doesn't know about.
const brandError = computed(
    () => (form.errors as Record<string, string>).brand,
);
</script>

<template>
    <Head title="Products" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Products"
            description="A brand's shop catalogue, with stock per variant"
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            :filters="filterDefs"
            search-placeholder="Search name or slug…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
            @update:filter="setFilter"
        >
            <template #actions>
                <Dialog
                    :open="open"
                    @update:open="
                        (value: boolean) => {
                            open = value;
                            if (!value) {
                                form.reset();
                            }
                        }
                    "
                >
                    <DialogTrigger as-child>
                        <Button :disabled="!selection?.brandId">
                            <Plus class="size-4" />
                            New product
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Create product</DialogTitle>
                            <DialogDescription>
                                Image, event, tax class and variants are set on
                                the next screen.
                            </DialogDescription>
                        </DialogHeader>
                        <form
                            class="flex flex-col gap-4"
                            @submit.prevent="submit"
                        >
                            <InputError :message="brandError" />
                            <div class="grid gap-2">
                                <Label for="product-name">Name</Label>
                                <Input id="product-name" v-model="form.name" />
                                <InputError :message="form.errors.name" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="product-slug">Slug</Label>
                                <Input id="product-slug" v-model="form.slug" />
                                <InputError :message="form.errors.slug" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="product-price">Price (net)</Label>
                                <Input
                                    id="product-price"
                                    v-model="form.unitAmountNet"
                                    inputmode="decimal"
                                />
                                <InputError
                                    :message="form.errors.unitAmountNet"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="product-stock"
                                    >Stock capacity</Label
                                >
                                <Input
                                    id="product-stock"
                                    v-model.number="form.stockCapacity"
                                    type="number"
                                    min="0"
                                />
                                <InputError
                                    :message="form.errors.stockCapacity"
                                />
                            </div>
                            <div class="flex items-center gap-3">
                                <Button
                                    type="submit"
                                    :disabled="form.processing"
                                >
                                    Create
                                </Button>
                                <span
                                    v-if="form.isDirty"
                                    role="status"
                                    class="text-sm text-warning"
                                >
                                    Unsaved changes
                                </span>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
            </template>
        </DataTableToolbar>

        <p
            v-if="!selection?.brandId"
            class="text-sm text-text-muted"
            role="status"
        >
            Pick a brand in the console shell to add a product.
        </p>

        <DataTable
            data-test="products-table"
            :columns="columns"
            :data="products.data"
            :sorting="sorting"
            :row-count="products.total"
            :pagination="pagination"
            :get-row-id="(row: ProductRow) => row.id"
            items-label="products"
            empty-message="No products found."
            row-clickable
            @row-click="(row: ProductRow) => router.visit(editProduct(row.id))"
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

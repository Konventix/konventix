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
import type { DataTableFilters } from '@/composables/useDataTable';
import { useDataTable } from '@/composables/useDataTable';
import {
    edit as editBrand,
    index as brandsIndex,
    store as createBrand,
} from '@/routes/admin/brands';
import { brandColumns } from './columns';
import type { BrandRow } from './columns';

interface PaginatedBrands {
    data: BrandRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    brands: PaginatedBrands;
    filters: DataTableFilters;
}>();

const { filters, setSearch, toggleSort, setPerPage, setPage } = useDataTable(
    () => brandsIndex().url,
    props.filters,
);

const columns = brandColumns();

const sorting = computed<SortingState>(() =>
    filters.sort ? [{ id: filters.sort, desc: filters.dir === 'desc' }] : [],
);

function onSortingChange(next: SortingState): void {
    if (next.length > 0) {
        toggleSort(next[0].id);
    }
}

const pagination = computed(() => ({
    currentPage: props.brands.current_page,
    lastPage: props.brands.last_page,
    total: props.brands.total,
    from: props.brands.from,
    to: props.brands.to,
}));

const open = ref(false);

const form = useForm({
    name: '',
    slug: '',
    hosts: [''],
});

function submit(): void {
    form.transform((data) => ({
        ...data,
        hosts: data.hosts.filter((host) => host.trim() !== ''),
    })).post(createBrand().url, {
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
</script>

<template>
    <Head :title="$t('Brands')" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="$t('Brands')"
            :description="
                $t('The operator\'s brands, their hosts and features')
            "
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            :search-placeholder="$t('Search brands…')"
            @update:search="setSearch"
            @update:per-page="setPerPage"
        >
            <template #actions>
                <Dialog v-model:open="open">
                    <DialogTrigger as-child>
                        <Button>
                            <Plus class="size-4" />
                            {{ $t('New brand') }}
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{{ $t('Create brand') }}</DialogTitle>
                        </DialogHeader>
                        <form
                            class="flex flex-col gap-4"
                            @submit.prevent="submit"
                        >
                            <div class="grid gap-2">
                                <Label for="brand-name">{{ $t('Name') }}</Label>
                                <Input id="brand-name" v-model="form.name" />
                                <InputError :message="form.errors.name" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="brand-slug">{{ $t('Slug') }}</Label>
                                <Input id="brand-slug" v-model="form.slug" />
                                <InputError :message="form.errors.slug" />
                            </div>
                            <div class="grid gap-2">
                                <Label>{{ $t('Hosts') }}</Label>
                                <div
                                    v-for="(_, index) in form.hosts"
                                    :key="index"
                                    class="flex gap-2"
                                >
                                    <Input
                                        v-model="form.hosts[index]"
                                        placeholder="brand.example"
                                    />
                                </div>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    class="self-start"
                                    @click="form.hosts.push('')"
                                >
                                    {{ $t('Add host') }}
                                </Button>
                                <InputError :message="form.errors.hosts" />
                            </div>
                            <Button type="submit" :disabled="form.processing">
                                {{ $t('Create') }}
                            </Button>
                        </form>
                    </DialogContent>
                </Dialog>
            </template>
        </DataTableToolbar>

        <DataTable
            data-test="brands-table"
            :columns="columns"
            :data="brands.data"
            :sorting="sorting"
            :row-count="brands.total"
            :pagination="pagination"
            :get-row-id="(row: BrandRow) => row.id"
            :items-label="$t('brands')"
            :empty-message="$t('No brands found.')"
            row-clickable
            @row-click="(row: BrandRow) => router.visit(editBrand(row.id))"
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

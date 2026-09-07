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
    edit as editPolicy,
    index as policiesIndex,
    store as storePolicy,
} from '@/routes/admin/policies';

/**
 * Every brand's legal documents in one list. The column that matters is
 * "published": a policy with versions but nothing published binds nobody,
 * and a required one that is published is in front of every user.
 */
type PolicyRow = {
    id: string;
    name: string;
    slug: string;
    /** 'operator' when every brand inherits this text (GitLab #76). */
    scope: 'operator' | 'brand';
    brandName: string;
    /** Brands that override this operator text with one of their own. */
    shadowedByBrands: number;
    /** Whether this brand row is such an override. */
    shadowsOperator: boolean;
    versions: number;
    publishedVersion: string | null;
    requiresConsent: boolean;
    effectiveAt: string | null;
};

interface PaginatedPolicies {
    data: PolicyRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    policies: PaginatedPolicies;
    brands: { id: string; name: string }[];
    filters: DataTableFilters;
}>();

const { filters, setSearch, toggleSort, setPerPage, setPage } = useDataTable(
    () => policiesIndex().url,
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
    currentPage: props.policies.current_page,
    lastPage: props.policies.last_page,
    total: props.policies.total,
    from: props.policies.from,
    to: props.policies.to,
}));

const columns: ColumnDef<PolicyRow>[] = [
    {
        accessorKey: 'name',
        header: 'Policy',
        enableSorting: true,
        cell: ({ row }) =>
            h('div', { class: 'flex flex-col' }, [
                h(
                    Link,
                    {
                        href: editPolicy(row.original.id).url,
                        class: 'font-medium hover:text-accent',
                    },
                    () => row.original.name,
                ),
                h(
                    'span',
                    { class: 'text-xs text-text-muted' },
                    row.original.slug,
                ),
            ]),
    },
    {
        id: 'brand',
        header: 'Scope',
        cell: ({ row }) =>
            h('div', { class: 'flex flex-col' }, [
                h(
                    'span',
                    {
                        class:
                            row.original.scope === 'operator'
                                ? 'font-medium text-text'
                                : 'text-text-muted',
                    },
                    row.original.brandName,
                ),
                // What the scope actually means for who is bound. An
                // operator text every brand has overridden binds nobody.
                row.original.scope === 'operator' &&
                row.original.shadowedByBrands > 0
                    ? h(
                          'span',
                          { class: 'text-xs text-warning' },
                          `overridden by ${row.original.shadowedByBrands} brand${row.original.shadowedByBrands === 1 ? '' : 's'}`,
                      )
                    : null,
                row.original.shadowsOperator
                    ? h(
                          'span',
                          { class: 'text-xs text-text-muted' },
                          'overrides the operator text',
                      )
                    : null,
            ]),
    },
    {
        id: 'published',
        header: 'Published',
        cell: ({ row }) =>
            row.original.publishedVersion === null
                ? h('span', { class: 'text-text-muted' }, 'Not published')
                : h('div', { class: 'flex flex-col' }, [
                      h('span', {}, row.original.publishedVersion),
                      h(
                          'span',
                          { class: 'text-xs text-text-muted' },
                          row.original.effectiveAt ?? '',
                      ),
                  ]),
    },
    {
        id: 'consent',
        header: 'Consent',
        cell: ({ row }) =>
            h(
                'span',
                {
                    class: row.original.requiresConsent
                        ? 'inline-flex rounded-full bg-accent/15 px-2 py-0.5 text-xs font-medium text-accent'
                        : 'text-text-muted',
                },
                row.original.requiresConsent ? 'required' : 'informational',
            ),
    },
    {
        id: 'versions',
        header: 'Versions',
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                String(row.original.versions),
            ),
    },
];

const open = ref(false);

const form = useForm({
    brand_id: props.brands[0]?.id ?? '',
    name: '',
    slug: '',
});

/** The slug follows the name until someone types their own. */
const slugTouched = ref(false);

watch(
    () => form.name,
    (name) => {
        if (!slugTouched.value) {
            form.slug = name
                .toLowerCase()
                .normalize('NFKD')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }
    },
);

function submit(): void {
    form.post(storePolicy().url, {
        onSuccess: () => {
            form.reset();
            slugTouched.value = false;
            open.value = false;
        },
    });
}
</script>

<template>
    <Head title="Policies" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Policies"
            description="Terms, privacy policies and house rules, per brand"
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            search-placeholder="Search name or slug…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
        >
            <template #actions>
                <Dialog v-model:open="open">
                    <DialogTrigger as-child>
                        <Button>
                            <Plus class="size-4" />
                            New policy
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Create policy</DialogTitle>
                        </DialogHeader>
                        <form
                            class="flex flex-col gap-4"
                            @submit.prevent="submit"
                        >
                            <div class="grid gap-2">
                                <Label for="policy-brand">Scope</Label>
                                <select
                                    id="policy-brand"
                                    v-model="form.brand_id"
                                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                                    aria-describedby="policy-brand-hint"
                                >
                                    <option value="">
                                        Operator-wide (all brands)
                                    </option>
                                    <option
                                        v-for="brand in props.brands"
                                        :key="brand.id"
                                        :value="brand.id"
                                    >
                                        {{ brand.name }}
                                    </option>
                                </select>
                                <p
                                    id="policy-brand-hint"
                                    class="text-xs text-text-muted"
                                >
                                    An operator-wide text applies to every brand
                                    that has not published its own under the
                                    same slug.
                                </p>
                                <InputError :message="form.errors.brand_id" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="policy-name">Name</Label>
                                <Input id="policy-name" v-model="form.name" />
                                <InputError :message="form.errors.name" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="policy-slug">Slug</Label>
                                <Input
                                    id="policy-slug"
                                    v-model="form.slug"
                                    @input="slugTouched = true"
                                />
                                <p class="text-xs text-text-muted">
                                    Public URL path. It freezes once a version
                                    of this policy has been published.
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
            data-test="policies-table"
            :columns="columns"
            :data="props.policies.data"
            :sorting="sorting"
            :pagination="pagination"
            items-label="policies"
            empty-message="No policies yet."
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import { computed, h, onMounted, ref, watch } from 'vue';
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
import { formatDateTime, withZoneName } from '@/lib/datetime';
import {
    edit as editPolicy,
    index as policiesIndex,
    store as storePolicy,
} from '@/routes/admin/policies';
import CheckoutLegalTextGapNotice from '../../../components/CheckoutLegalTextGapNotice.vue';

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

type CheckoutRole = Konventix.Compliance.Data.CheckoutLegalTextRoleData;
type CheckoutGap = Konventix.Compliance.Data.CheckoutLegalTextGapData;

const props = defineProps<{
    policies: PaginatedPolicies;
    brands: { id: string; name: string }[];
    /** The slugs the checkout resolves, and what each one means (#200). */
    checkoutRoles: CheckoutRole[];
    /** Brands the checkout currently resolves nothing for (#200). */
    checkoutGaps: CheckoutGap[];
    /**
     * The slug and brand a `?create=` link arrived with (#222): the warning
     * elsewhere in the console links straight into this page's create
     * dialog, prefilled, so nobody retypes the slug that caused the gap.
     */
    createIntent: { slug: string; brandId: string | null } | null;
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
                      // Rendered in the reader's own zone and naming it:
                      // the stored instant is UTC, and a legal effective
                      // date shown as bare digits reads as a different hour
                      // in every country the operator sells into.
                      row.original.effectiveAt === null
                          ? h(
                                'span',
                                { class: 'text-xs text-text-muted' },
                                'no date',
                            )
                          : h(
                                'time',
                                {
                                    class: 'text-xs text-text-muted',
                                    datetime: row.original.effectiveAt,
                                },
                                formatDateTime(
                                    row.original.effectiveAt,
                                    withZoneName,
                                ),
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

/**
 * Picking a role fills in both fields — the slug is the whole point, and
 * typing it by hand is what produced the `agb` policy no checkout ever
 * found (#200). Leaving the choice empty keeps the free-form policy the
 * purchase path never asks for.
 */
const checkoutRoleChoice = ref('');

watch(checkoutRoleChoice, (slug) => {
    const role = props.checkoutRoles.find(
        (candidate) => candidate.slug === slug,
    );

    if (role === undefined) {
        return;
    }

    form.name = role.name;
    form.slug = role.slug;
    slugTouched.value = true;
});

/**
 * Open the create dialog on a named role for a named brand (#222) — from
 * the warning's own buttons, and from a `?create=` link that arrived from
 * another console page.
 */
function startCreating(slug: string, brandId: string | null): void {
    checkoutRoleChoice.value = slug;
    form.brand_id = brandId ?? props.brands[0]?.id ?? '';
    open.value = true;
}

onMounted(() => {
    if (props.createIntent !== null) {
        startCreating(props.createIntent.slug, props.createIntent.brandId);
    }
});

function submit(): void {
    form.post(storePolicy().url, {
        onSuccess: () => {
            form.reset();
            checkoutRoleChoice.value = '';
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

        <!--
            #200/#222: `CheckoutLegalTexts::SLUGS` decides which texts a
            checkout asks for. A brand it resolves nothing under sells with
            no consent control at all. The buttons open this page's own
            create dialog rather than linking back to it.
        -->
        <CheckoutLegalTextGapNotice
            :gaps="props.checkoutGaps"
            :roles="props.checkoutRoles"
        >
            <template #actions="{ gap }">
                <span class="mt-1 flex flex-wrap gap-2">
                    <Button
                        v-for="slug in gap.missingSlugs"
                        :key="slug"
                        size="sm"
                        variant="outline"
                        @click="startCreating(slug, gap.brandId)"
                    >
                        <Plus class="size-4" />
                        Create
                        {{
                            props.checkoutRoles.find(
                                (role) => role.slug === slug,
                            )?.name ?? slug
                        }}
                        for {{ gap.brandName }}
                    </Button>
                </span>
            </template>
        </CheckoutLegalTextGapNotice>

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
                                <Label for="policy-checkout-role">
                                    Checkout role
                                </Label>
                                <select
                                    id="policy-checkout-role"
                                    v-model="checkoutRoleChoice"
                                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                                    aria-describedby="policy-checkout-role-hint"
                                >
                                    <option value="">
                                        None — the checkout never asks for this
                                        text
                                    </option>
                                    <option
                                        v-for="role in props.checkoutRoles"
                                        :key="role.slug"
                                        :value="role.slug"
                                    >
                                        {{ role.name }} ({{ role.slug }})
                                    </option>
                                </select>
                                <p
                                    id="policy-checkout-role-hint"
                                    class="text-xs text-text-muted"
                                >
                                    The purchase path looks for exactly these
                                    two slugs. A text under any other slug is a
                                    perfectly good policy the checkout never
                                    mentions.
                                </p>
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
                                    of this policy has been published. The
                                    checkout resolves
                                    <code>terms</code> and
                                    <code>withdrawal</code>; any other slug it
                                    never asks for.
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

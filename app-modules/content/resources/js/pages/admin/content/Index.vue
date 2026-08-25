<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ExternalLink, MessageSquare, Plus } from '@lucide/vue';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    DataTableFilterDef,
    DataTableFilters,
} from '@/composables/useDataTable';
import { useDataTable } from '@/composables/useDataTable';
import {
    edit as editEntry,
    index as contentIndex,
    store as storeEntry,
} from '@/routes/admin/content';
import { index as commentsIndex } from '@/routes/admin/content/comments';

/**
 * Editorial entries across every brand. Brand, type and state are
 * server-side filters rather than client-side ones, so a page of 15 rows is a
 * page of the 15 rows that matched — the same contract every other console
 * index keeps. Brand defaults to the console selection; there is no event
 * filter yet, because Content has no event read model (see its TODO.md).
 */
type EntryRow = {
    id: string;
    brandName: string;
    title: string;
    slug: string;
    type: Konventix.Content.Enums.ContentType;
    state: Konventix.Content.Enums.PublicationState;
    publishedAt: string | null;
    updatedAt: string | null;
    /** Absent while the entry is not live — a draft has no reader-facing page. */
    publicUrl: string | null;
};

interface PaginatedEntries {
    data: EntryRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    entries: PaginatedEntries;
    brands: { id: string; name: string }[];
    types: Konventix.Content.Enums.ContentType[];
    states: Konventix.Content.Enums.PublicationState[];
    filters: DataTableFilters & {
        brand: string | null;
        type: string | null;
        state: string | null;
    };
    pendingComments: number;
}>();

const { filters, setSearch, setFilter, toggleSort, setPerPage, setPage } =
    useDataTable(() => contentIndex().url, props.filters);

/**
 * The brand filter defaults to the console selection; `clearValue: 'all'`
 * makes "All brands" survive the next visit instead of falling back to the
 * selection again. Type and state have no default, so they clear to nothing.
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
    {
        name: 'type',
        label: 'Types',
        value: props.filters.type,
        options: props.types.map((type) => ({ value: type, label: type })),
    },
    {
        name: 'state',
        label: 'States',
        value: props.filters.state,
        options: props.states.map((state) => ({ value: state, label: state })),
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
    currentPage: props.entries.current_page,
    lastPage: props.entries.last_page,
    total: props.entries.total,
    from: props.entries.from,
    to: props.entries.to,
}));

const stateClasses: Record<Konventix.Content.Enums.PublicationState, string> = {
    draft: 'bg-subtle text-text-muted',
    scheduled: 'bg-accent/15 text-accent',
    published: 'bg-success/15 text-success',
    unpublished: 'bg-warning/15 text-warning',
    archived: 'bg-subtle text-text-muted',
};

const columns: ColumnDef<EntryRow>[] = [
    {
        accessorKey: 'title',
        header: 'Title',
        enableSorting: true,
        cell: ({ row }) =>
            h('div', { class: 'flex flex-col' }, [
                h(
                    Link,
                    {
                        href: editEntry(row.original.id).url,
                        class: 'font-medium hover:text-accent',
                    },
                    () => row.original.title,
                ),
                row.original.publicUrl
                    ? h(
                          'a',
                          {
                              href: row.original.publicUrl,
                              target: '_blank',
                              rel: 'noopener',
                              class: 'inline-flex w-fit items-center gap-1 text-xs text-text-muted hover:text-accent',
                              // The row already links to the editor, so this
                              // one must not also trigger it.
                              onClick: (event: MouseEvent) =>
                                  event.stopPropagation(),
                          },
                          () => [
                              row.original.slug,
                              h(ExternalLink, { class: 'size-3' }),
                          ],
                      )
                    : h(
                          'span',
                          { class: 'text-xs text-text-muted' },
                          row.original.slug,
                      ),
            ]),
    },
    {
        accessorKey: 'type',
        header: 'Type',
        enableSorting: true,
        cell: ({ row }) =>
            h('span', { class: 'text-text-muted' }, row.original.type),
    },
    {
        accessorKey: 'state',
        header: 'State',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                'span',
                {
                    class: `inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${stateClasses[row.original.state] ?? 'bg-subtle text-text-muted'}`,
                },
                row.original.state,
            ),
    },
    {
        id: 'brand',
        header: 'Brand',
        cell: ({ row }) =>
            h('span', { class: 'text-text-muted' }, row.original.brandName),
    },
    {
        accessorKey: 'published_at',
        header: 'Published',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                row.original.publishedAt ?? '—',
            ),
    },
];

const open = ref(false);

const form = useForm({
    brand_id: props.brands[0]?.id ?? '',
    type: 'news',
    title: '',
    slug: '',
    teaser: null as string | null,
    body: null as string | null,
    audience: 'everyone',
    urgency: null as string | null,
    eventId: null as string | null,
    commentsEnabled: false,
});

/**
 * The slug follows the title until someone types their own; after that the
 * field is theirs. An editor renaming a headline should not silently break a
 * published URL, so this only ever fills a slug that is still untouched.
 */
const slugTouched = ref(false);

watch(
    () => form.title,
    (title) => {
        if (!slugTouched.value) {
            form.slug = title
                .toLowerCase()
                .normalize('NFKD')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }
    },
);

function submit(): void {
    form.post(storeEntry().url, {
        onSuccess: () => {
            form.reset();
            slugTouched.value = false;
            open.value = false;
        },
    });
}
</script>

<template>
    <Head title="Content" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Content"
            description="News, announcements and editorial pages across every brand"
        >
            <Button variant="outline" as-child>
                <Link :href="commentsIndex().url">
                    <MessageSquare class="size-4" />
                    Comments
                    <span
                        v-if="props.pendingComments > 0"
                        class="ml-1 inline-flex min-w-5 justify-center rounded-full bg-warning/15 px-1.5 text-xs font-medium text-warning"
                    >
                        {{ props.pendingComments }}
                    </span>
                </Link>
            </Button>
        </AdminPageHeader>

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
                            New entry
                        </Button>
                    </DialogTrigger>
                    <DialogContent class="max-h-[85vh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>Create entry</DialogTitle>
                        </DialogHeader>
                        <form
                            class="flex flex-col gap-4"
                            @submit.prevent="submit"
                        >
                            <div class="grid gap-2">
                                <Label for="entry-brand">Brand</Label>
                                <Select v-model="form.brand_id">
                                    <SelectTrigger id="entry-brand">
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
                                <Label for="entry-type">Type</Label>
                                <Select v-model="form.type">
                                    <SelectTrigger id="entry-type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="type in props.types"
                                            :key="type"
                                            :value="type"
                                        >
                                            {{ type }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError :message="form.errors.type" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="entry-title">Title</Label>
                                <Input id="entry-title" v-model="form.title" />
                                <InputError :message="form.errors.title" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="entry-slug">Slug</Label>
                                <Input
                                    id="entry-slug"
                                    v-model="form.slug"
                                    @input="slugTouched = true"
                                />
                                <InputError :message="form.errors.slug" />
                            </div>
                            <p class="text-sm text-text-muted">
                                New entries start as drafts — write the body on
                                the next screen, then publish from there.
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
            data-test="content-table"
            :columns="columns"
            :data="props.entries.data"
            :sorting="sorting"
            :pagination="pagination"
            items-label="entries"
            empty-message="No entries yet."
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

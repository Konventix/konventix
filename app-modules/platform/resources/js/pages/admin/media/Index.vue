<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Trash2, Upload } from '@lucide/vue';
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
    destroy as destroyMedia,
    index as mediaIndex,
    store as storeMedia,
} from '@/routes/admin/media';

type MediaRow = App.Data.MediaItemData;

interface PaginatedMedia {
    data: MediaRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    media: PaginatedMedia;
    brands: { id: string; name: string }[];
    filters: DataTableFilters;
}>();

const { filters, setSearch, toggleSort, setPerPage, setPage } = useDataTable(
    () => mediaIndex().url,
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
    currentPage: props.media.current_page,
    lastPage: props.media.last_page,
    total: props.media.total,
    from: props.media.from,
    to: props.media.to,
}));

const uploadOpen = ref(false);

const form = useForm<{
    brand_id: string;
    file: File | null;
    alt_text: string;
}>({
    brand_id: props.brands[0]?.id ?? '',
    file: null,
    alt_text: '',
});

function submitUpload(): void {
    form.post(storeMedia().url, {
        forceFormData: true,
        onSuccess: () => {
            form.reset('file', 'alt_text');
            uploadOpen.value = false;
        },
    });
}

function deleteItem(row: MediaRow): void {
    router.delete(destroyMedia(row.id).url, { preserveScroll: true });
}

function formatBytes(bytes: number): string {
    if (bytes >= 1_048_576) {
        return `${(bytes / 1_048_576).toFixed(1)} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

const columns: ColumnDef<MediaRow>[] = [
    {
        id: 'preview',
        header: '',
        enableSorting: false,
        cell: ({ row }) =>
            row.original.thumbUrl || row.original.url
                ? h('img', {
                      src: row.original.thumbUrl ?? row.original.url,
                      alt: row.original.altText ?? '',
                      class: 'h-10 w-14 rounded border border-border object-cover',
                  })
                : h('span', { class: 'text-text-muted text-xs' }, '—'),
    },
    {
        accessorKey: 'original_filename',
        accessorFn: (row) => row.originalFilename,
        header: 'File',
        enableSorting: true,
        cell: ({ row }) =>
            h('div', { class: 'flex flex-col' }, [
                h(
                    'a',
                    {
                        href: row.original.url ?? '#',
                        target: '_blank',
                        class: 'font-medium hover:text-accent',
                    },
                    row.original.originalFilename,
                ),
                h(
                    'span',
                    { class: 'text-xs text-text-muted' },
                    row.original.mimeType,
                ),
            ]),
    },
    {
        accessorKey: 'size_bytes',
        accessorFn: (row) => row.sizeBytes,
        header: 'Size',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'tabular-nums text-text-muted' },
                formatBytes(row.original.sizeBytes),
            ),
    },
    {
        id: 'usage',
        header: 'Used by',
        enableSorting: false,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                row.original.usageCount === 0
                    ? 'unused'
                    : `${row.original.usageCount} entit${row.original.usageCount === 1 ? 'y' : 'ies'}`,
            ),
    },
    {
        accessorKey: 'created_at',
        accessorFn: (row) => row.uploadedAt,
        header: 'Uploaded',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                row.original.uploadedAt ?? '—',
            ),
    },
    {
        id: 'actions',
        header: '',
        enableSorting: false,
        cell: ({ row }) =>
            row.original.usageCount === 0
                ? h(
                      Button,
                      {
                          variant: 'ghost',
                          size: 'sm',
                          onClick: () => deleteItem(row.original),
                      },
                      () => h(Trash2, { class: 'size-4 text-danger' }),
                  )
                : null,
    },
];
</script>

<template>
    <Head title="Media" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Media"
            description="Shared file library for badges, themes and pages"
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            search-placeholder="Search files…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
        >
            <template #actions>
                <Dialog v-model:open="uploadOpen">
                    <DialogTrigger as-child>
                        <Button>
                            <Upload class="size-4" />
                            Upload
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Upload file</DialogTitle>
                        </DialogHeader>
                        <form
                            class="flex flex-col gap-4"
                            @submit.prevent="submitUpload"
                        >
                            <div class="grid gap-2">
                                <Label for="media-brand">Brand</Label>
                                <select
                                    id="media-brand"
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
                                <Label for="media-file">File</Label>
                                <input
                                    id="media-file"
                                    type="file"
                                    accept="image/*,.svg,.pdf"
                                    class="text-sm"
                                    @change="
                                        form.file =
                                            ($event.target as HTMLInputElement)
                                                .files?.[0] ?? null
                                    "
                                />
                                <InputError :message="form.errors.file" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="media-alt">Alt text (optional)</Label>
                                <Input id="media-alt" v-model="form.alt_text" />
                                <InputError :message="form.errors.alt_text" />
                            </div>
                            <Button type="submit" :disabled="form.processing">
                                Upload
                            </Button>
                        </form>
                    </DialogContent>
                </Dialog>
            </template>
        </DataTableToolbar>

        <DataTable
            data-test="media-table"
            :columns="columns"
            :data="props.media.data"
            :sorting="sorting"
            :pagination="pagination"
            items-label="files"
            empty-message="No media yet — upload logos, block images, or badge assets."
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

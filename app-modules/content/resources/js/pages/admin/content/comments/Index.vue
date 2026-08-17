<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Flag } from '@lucide/vue';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import { computed, h } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import DataTable from '@/components/admin/DataTable.vue';
import DataTableToolbar from '@/components/admin/DataTableToolbar.vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { DataTableFilters } from '@/composables/useDataTable';
import { useDataTable } from '@/composables/useDataTable';
import { edit as editEntry } from '@/routes/admin/content';
import {
    index as commentsIndex,
    moderate as moderateComment,
} from '@/routes/admin/content/comments';

/**
 * The moderation queue. It opens on "needs attention" — pending plus anything
 * reported — rather than on every comment ever posted, because a flat list of
 * all comments is an archive, not a tool.
 *
 * Reject and hide are separate buttons on purpose: rejecting turns down a
 * submission nobody saw, hiding removes something that was already public,
 * and a moderator reviewing their own history needs to know which they did.
 */
type CommentRow = {
    id: string;
    entryId: string;
    entryTitle: string;
    authorUserId: string;
    body: string;
    state: Konventix.Content.Enums.CommentState;
    reportCount: number;
    postedAt: string | null;
    moderatedAt: string | null;
};

interface PaginatedComments {
    data: CommentRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    comments: PaginatedComments;
    states: Konventix.Content.Enums.CommentState[];
    filters: DataTableFilters;
}>();

const { filters, setSearch, toggleSort, setPerPage, setPage, navigate } =
    useDataTable(() => commentsIndex().url, props.filters);

const sorting = computed<SortingState>(() =>
    filters.sort ? [{ id: filters.sort, desc: filters.dir === 'desc' }] : [],
);

function onSortingChange(next: SortingState): void {
    if (next.length > 0) {
        toggleSort(next[0].id);
    }
}

function setState(value: string): void {
    filters.state = value;
    navigate({ page: undefined });
}

const pagination = computed(() => ({
    currentPage: props.comments.current_page,
    lastPage: props.comments.last_page,
    total: props.comments.total,
    from: props.comments.from,
    to: props.comments.to,
}));

const stateClasses: Record<Konventix.Content.Enums.CommentState, string> = {
    pending: 'bg-warning/15 text-warning',
    approved: 'bg-success/15 text-success',
    rejected: 'bg-danger/15 text-danger',
    hidden: 'bg-subtle text-text-muted',
};

function moderate(
    comment: CommentRow,
    state: Konventix.Content.Enums.CommentState,
): void {
    router.post(
        moderateComment(comment.id).url,
        { state },
        { preserveScroll: true },
    );
}

const columns: ColumnDef<CommentRow>[] = [
    {
        id: 'comment',
        header: 'Comment',
        cell: ({ row }) =>
            h('div', { class: 'flex max-w-xl flex-col gap-1' }, [
                h(
                    'p',
                    { class: 'text-sm whitespace-pre-line' },
                    row.original.body,
                ),
                h(
                    'div',
                    {
                        class: 'flex items-center gap-2 text-xs text-text-muted',
                    },
                    [
                        h(
                            Link,
                            {
                                href: editEntry(row.original.entryId).url,
                                class: 'hover:text-accent',
                            },
                            () => row.original.entryTitle,
                        ),
                        h('span', '·'),
                        // Bare ULID until Platform's identity read model lands.
                        h(
                            'span',
                            { class: 'font-mono' },
                            row.original.authorUserId,
                        ),
                    ],
                ),
            ]),
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
        accessorKey: 'report_count',
        header: 'Reports',
        enableSorting: true,
        cell: ({ row }) =>
            row.original.reportCount > 0
                ? h(
                      'span',
                      { class: 'inline-flex items-center gap-1 text-danger' },
                      [
                          h(Flag, { class: 'size-3.5' }),
                          String(row.original.reportCount),
                      ],
                  )
                : h('span', { class: 'text-text-muted' }, '—'),
    },
    {
        accessorKey: 'created_at',
        header: 'Posted',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                row.original.postedAt ?? '—',
            ),
    },
    {
        id: 'actions',
        header: '',
        cell: ({ row }) =>
            h('div', { class: 'flex justify-end gap-2' }, [
                row.original.state !== 'approved'
                    ? h(
                          Button,
                          {
                              size: 'sm',
                              variant: 'outline',
                              onClick: () => moderate(row.original, 'approved'),
                          },
                          () => 'Approve',
                      )
                    : null,
                row.original.state === 'pending'
                    ? h(
                          Button,
                          {
                              size: 'sm',
                              variant: 'outline',
                              onClick: () => moderate(row.original, 'rejected'),
                          },
                          () => 'Reject',
                      )
                    : null,
                row.original.state !== 'hidden' &&
                row.original.state !== 'pending'
                    ? h(
                          Button,
                          {
                              size: 'sm',
                              variant: 'outline',
                              onClick: () => moderate(row.original, 'hidden'),
                          },
                          () => 'Hide',
                      )
                    : null,
            ]),
    },
];
</script>

<template>
    <Head title="Comments" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Comments"
            description="Moderation queue across every brand"
        />

        <DataTableToolbar
            :search="filters.search ?? ''"
            :per-page="filters.per_page ?? 15"
            search-placeholder="Search comment text…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
        >
            <template #actions>
                <Select
                    :model-value="(filters.state as string) ?? 'attention'"
                    @update:model-value="(value) => setState(String(value))"
                >
                    <SelectTrigger class="w-44" aria-label="Filter by state">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="attention">
                            Needs attention
                        </SelectItem>
                        <SelectItem value="all">All comments</SelectItem>
                        <SelectItem
                            v-for="state in props.states"
                            :key="state"
                            :value="state"
                        >
                            {{ state }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </template>
        </DataTableToolbar>

        <DataTable
            data-test="comments-table"
            :columns="columns"
            :data="props.comments.data"
            :sorting="sorting"
            :pagination="pagination"
            items-label="comments"
            empty-message="Nothing to moderate."
            @update:sorting="onSortingChange"
            @update:page="setPage"
        />
    </div>
</template>

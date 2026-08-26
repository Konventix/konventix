<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Flag } from '@lucide/vue';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import { computed, h } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import DataTable from '@/components/admin/DataTable.vue';
import DataTableToolbar from '@/components/admin/DataTableToolbar.vue';
import { Button } from '@/components/ui/button';
import { useConsoleSelection } from '@/composables/useConsoleSelection';
import type {
    DataTableFilterDef,
    DataTableFilters,
} from '@/composables/useDataTable';
import { seedSelectionFilter, useDataTable } from '@/composables/useDataTable';
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
 *
 * Authors are named, not spelled out as ULIDs: the name comes from Content's
 * own author read model and the link points at Identity's account page.
 */
type CommentRow = {
    id: string;
    entryId: string;
    entryTitle: string;
    authorName: string;
    /** Identity's account page for this author. */
    authorUrl: string;
    body: string;
    state: Konventix.Content.Enums.CommentState;
    reportCount: number;
    reactionCount: number;
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
    brands: { id: string; name: string }[];
    states: Konventix.Content.Enums.CommentState[];
    filters: DataTableFilters & {
        brand: string | null;
        state: string | null;
    };
}>();

const selection = useConsoleSelection();

const { filters, setSearch, setFilter, toggleSort, setPerPage, setPage } =
    useDataTable(() => commentsIndex().url, {
        ...props.filters,
        // An absent `state` means "the queue", so a cleared state filter has
        // to keep sending `all` — the same reason `seedSelectionFilter`
        // exists for the brand, with a constant default instead of a pick.
        state: props.filters.state ?? 'all',
        brand: seedSelectionFilter(
            props.filters.brand,
            selection.value?.brandId,
        ),
    });

/**
 * `attention` is not a `CommentState` — it is the queue, and this list's
 * default — so it sits among the states as its own option, and `clearValue`
 * makes "All comments" survive the next search instead of falling back to
 * the queue. The brand filter defaults to the console selection the same way.
 */
const filterDefs = computed<DataTableFilterDef[]>(() => [
    {
        name: 'state',
        label: 'Comments',
        value: props.filters.state,
        options: [
            { value: 'attention', label: 'Needs attention' },
            ...props.states.map((state) => ({
                value: state,
                label: state[0].toUpperCase() + state.slice(1),
            })),
        ],
        clearValue: 'all',
    },
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
                        h(
                            Link,
                            {
                                href: row.original.authorUrl,
                                class: 'underline-offset-4 hover:text-accent hover:underline',
                            },
                            () => row.original.authorName,
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
        accessorKey: 'reaction_count',
        header: 'Likes',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-text-muted' },
                String(row.original.reactionCount),
            ),
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
            :filters="filterDefs"
            search-placeholder="Search comment text…"
            @update:search="setSearch"
            @update:per-page="setPerPage"
            @update:filter="setFilter"
        />

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

<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';
import { show as showEntry } from '@/routes/content';

/**
 * The brand's public news listing. Announcements appear here alongside news:
 * an announcement is still an entry, and someone who missed the banner should
 * be able to find it (D-CNT-08 keeps the two apart as *intent*, not as two
 * archives).
 */
type Entry = Konventix.Content.Data.PublicEntryData;

interface PaginatedEntries {
    data: Entry[];
    current_page: number;
    last_page: number;
}

const props = defineProps<{
    entries: PaginatedEntries;
}>();

function formatDate(value: string | null): string {
    return formatDateTime(value, { dateStyle: 'long', timeStyle: undefined });
}

function goToPage(page: number): void {
    router.get(
        window.location.pathname,
        { page },
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <Head title="News" />

    <div
        class="mx-auto flex w-full max-w-3xl flex-col gap-8 p-6 text-text lg:p-8"
    >
        <h1 class="text-3xl font-semibold">News</h1>

        <p
            v-if="props.entries.data.length === 0"
            class="text-sm text-text-muted"
        >
            Nothing published yet.
        </p>

        <article
            v-for="entry in props.entries.data"
            :key="entry.slug"
            class="flex flex-col gap-2 border-b border-border pb-6 last:border-b-0"
        >
            <div
                class="flex items-center gap-2 text-xs tracking-wide text-text-muted uppercase"
            >
                <span
                    v-if="entry.type === 'announcement'"
                    class="rounded-full bg-accent/15 px-2 py-0.5 text-accent"
                >
                    Announcement
                </span>
                <time :datetime="entry.publishedAt ?? undefined">
                    {{ formatDate(entry.publishedAt) }}
                </time>
            </div>

            <h2 class="text-xl font-semibold">
                <Link
                    :href="showEntry(entry.slug).url"
                    class="hover:text-accent"
                >
                    {{ entry.title }}
                </Link>
            </h2>

            <p v-if="entry.teaser" class="text-sm text-text-muted">
                {{ entry.teaser }}
            </p>

            <p v-if="entry.authorName" class="text-xs text-text-muted">
                by {{ entry.authorName }}
            </p>
        </article>

        <div
            v-if="props.entries.last_page > 1"
            class="flex items-center justify-between"
        >
            <Button
                variant="outline"
                :disabled="props.entries.current_page <= 1"
                @click="goToPage(props.entries.current_page - 1)"
            >
                Newer
            </Button>
            <span class="text-sm text-text-muted">
                Page {{ props.entries.current_page }} of
                {{ props.entries.last_page }}
            </span>
            <Button
                variant="outline"
                :disabled="
                    props.entries.current_page >= props.entries.last_page
                "
                @click="goToPage(props.entries.current_page + 1)"
            >
                Older
            </Button>
        </div>
    </div>
</template>

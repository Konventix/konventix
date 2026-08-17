<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { index as newsIndex, show as showEntry } from '@/routes/content';

/**
 * The "latest news" block a brand drops onto a static page. Its props are
 * resolved at render time by `LatestNewsBlock` on the server, so the list is
 * whatever is newest when the page is requested, not whatever was newest the
 * day an editor added the block.
 *
 * The prop shape is the block's server-side `resolve()` output — snake_case
 * `published_at` included, since that array is hand-built there rather than
 * projected through a `Data` class.
 */
type Entry = {
    id: string;
    slug: string;
    title: string;
    teaser: string | null;
    published_at: string | null;
};

defineProps<{
    heading: string | null;
    entries: Entry[];
}>();

const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'long' });

function formatDate(value: string | null): string {
    return value ? dateFormat.format(new Date(value)) : '';
}
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex items-baseline justify-between gap-4">
            <h2 v-if="heading" class="text-2xl font-semibold text-text">
                {{ heading }}
            </h2>
            <Link
                :href="newsIndex().url"
                class="inline-flex items-center gap-1 text-sm text-text-muted hover:text-accent"
            >
                All news
                <ArrowRight class="size-4" />
            </Link>
        </div>

        <p v-if="entries.length === 0" class="text-sm text-text-muted">
            Nothing published yet.
        </p>

        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <article
                v-for="entry in entries"
                :key="entry.id"
                class="flex flex-col gap-2 rounded-lg border border-border bg-surface-raised p-5"
            >
                <time
                    class="text-xs tracking-wide text-text-muted uppercase"
                    :datetime="entry.published_at ?? undefined"
                >
                    {{ formatDate(entry.published_at) }}
                </time>
                <h3 class="font-semibold text-text">
                    <Link
                        :href="showEntry(entry.slug).url"
                        class="hover:text-accent"
                    >
                        {{ entry.title }}
                    </Link>
                </h3>
                <p v-if="entry.teaser" class="text-sm text-text-muted">
                    {{ entry.teaser }}
                </p>
            </article>
        </div>
    </section>
</template>

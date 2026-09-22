<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { formatDateTime } from '@/lib/datetime';

/**
 * The live hub's announcements card (D-HUB-05, design §5.2): the last
 * three things the organizers said about this event, and the way to the
 * rest.
 *
 * The props are `AnnouncementsCardData`'s fields, spread onto this
 * component by the hub shell (`v-bind="card.payload"`) — the shell never
 * looks inside a payload (ADR-0003), so the generated interface is the
 * whole contract.
 *
 * Every URL comes from the payload, so this card imports no route helper
 * and nothing of another module's. `publishedAt` goes through the shared
 * formatter, which is the only way a timestamp reaches a screen — the
 * reader's own zone and the operator's format (`.ai/rules/js.md`).
 */
// Spelled out rather than `Konventix.Content.Data.AnnouncementsCardData`:
// a bare namespaced type fails the SFC compiler's macro type resolver (see
// `admission/tickets/Show.vue`). The entry type is nested, not top level,
// so it stays named. Mirrors `AnnouncementsCardData`.
type Props = {
    entries: Konventix.Content.Data.AnnouncementItemData[];
    newsUrl: string;
};

const props = defineProps<Props>();
</script>

<template>
    <div class="flex flex-col gap-4">
        <p v-if="props.entries.length === 0" class="text-sm text-text-muted">
            Noch keine Ankündigungen
        </p>

        <ul v-else class="flex flex-col gap-4">
            <li
                v-for="entry in props.entries"
                :key="entry.url"
                class="flex flex-col gap-1"
            >
                <Link
                    :href="entry.url"
                    class="font-medium text-text underline-offset-4 hover:underline"
                >
                    {{ entry.title }}
                </Link>
                <time
                    :datetime="entry.publishedAt"
                    class="text-xs text-text-muted"
                >
                    {{ formatDateTime(entry.publishedAt) }}
                </time>
                <p v-if="entry.teaser" class="text-sm text-text-muted">
                    {{ entry.teaser }}
                </p>
            </li>
        </ul>

        <Link
            :href="props.newsUrl"
            class="self-start text-sm text-accent underline-offset-4 hover:underline"
        >
            Alle News
        </Link>
    </div>
</template>

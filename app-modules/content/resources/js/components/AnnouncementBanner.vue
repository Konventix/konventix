<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Megaphone, X } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import { show as showEntry } from '@/routes/content';

/**
 * The site-wide banner for urgent announcements, mounted under the public
 * topbar through the module component registry — the same seam Messaging's
 * bell uses, so the shell holds no Content import (ADR-0003).
 *
 * It fetches once per browser page load rather than riding on Inertia props,
 * which keeps every public controller free of announcement plumbing and means
 * a brand that never announces anything pays one small request.
 *
 * Dismissal is per browser session and per entry: an announcement urgent
 * enough to interrupt should come back tomorrow if it is still live, but it
 * should not follow a reader across every page click after they have read it.
 */
type Entry = Konventix.Content.Data.PublicEntryData;

const announcements = ref<Entry[]>([]);
const dismissed = ref<string[]>(readDismissed());

function readDismissed(): string[] {
    if (typeof sessionStorage === 'undefined') {
        return [];
    }

    try {
        const raw = sessionStorage.getItem('content.dismissed-announcements');

        return raw ? (JSON.parse(raw) as string[]) : [];
    } catch {
        return [];
    }
}

function dismiss(slug: string): void {
    dismissed.value = [...dismissed.value, slug];

    try {
        sessionStorage.setItem(
            'content.dismissed-announcements',
            JSON.stringify(dismissed.value),
        );
    } catch {
        // A browser refusing storage is not a reason to keep the banner up.
    }
}

onMounted(async () => {
    try {
        const response = await fetch('/announcements', {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            return;
        }

        const payload = (await response.json()) as { announcements: Entry[] };
        announcements.value = payload.announcements;
    } catch {
        // The banner is an extra, never a precondition for the page.
    }
});
</script>

<template>
    <div
        v-for="announcement in announcements.filter(
            (entry) => !dismissed.includes(entry.slug),
        )"
        :key="announcement.slug"
        role="status"
        class="border-b border-danger/30 bg-danger/10"
    >
        <div
            class="mx-auto flex max-w-5xl items-start gap-3 px-6 py-3 text-sm text-text"
        >
            <Megaphone class="mt-0.5 size-4 shrink-0 text-danger" />
            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                <Link
                    :href="showEntry(announcement.slug).url"
                    class="font-medium hover:text-accent"
                >
                    {{ announcement.title }}
                </Link>
                <p v-if="announcement.teaser" class="text-text-muted">
                    {{ announcement.teaser }}
                </p>
            </div>
            <button
                type="button"
                class="shrink-0 rounded-full p-1 text-text-muted hover:text-text"
                aria-label="Dismiss announcement"
                @click="dismiss(announcement.slug)"
            >
                <X class="size-4" />
            </button>
        </div>
    </div>
</template>

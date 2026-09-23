<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, Settings } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';
import { edit as notificationSettings } from '@/routes/notification-preferences';
import {
    index as notificationsIndex,
    read as notificationsRead,
    readAll as notificationsReadAll,
} from '@/routes/notifications';

/**
 * The in-app inbox as a page — everything the bell's short list leaves out.
 *
 * Read and unread are listed together on purpose: an inbox that hid what you
 * had already read would make "what was that message again?" unanswerable.
 * Marking read goes through the same endpoints the bell uses; they answer
 * JSON to its `fetch` and a redirect back to this page's Inertia visit.
 */
type Notification = Konventix.Messaging.Data.NotificationData;

type PaginatedNotifications = {
    data: Notification[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

const props = defineProps<{
    notifications: PaginatedNotifications;
    unread: number;
}>();

function markRead(notification: Notification): void {
    router.post(
        notificationsRead({ notification: notification.id }).url,
        {},
        { preserveScroll: true },
    );
}

function markAllRead(): void {
    router.post(notificationsReadAll().url, {}, { preserveScroll: true });
}

function goToPage(page: number): void {
    router.get(
        notificationsIndex().url,
        { page },
        { preserveScroll: true, preserveState: true },
    );
}

/** Notification bodies are stored as HTML; the list shows plain text. */
function plainBody(body: string): string {
    return body.replace(/<[^>]*>/g, '');
}
</script>

<template>
    <Head :title="$t('Notifications')" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-6">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-col gap-1">
                <h1 class="text-2xl font-semibold text-text">
                    {{ $t('Notifications') }}
                </h1>
                <p class="text-sm text-text-muted">
                    <template v-if="props.unread > 0">
                        {{
                            $tChoice(
                                '{1} :count unread|[2,*] :count unread',
                                props.unread,
                            )
                        }}
                    </template>
                    <template v-else>{{ $t('Everything is read.') }}</template>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    v-if="props.unread > 0"
                    variant="secondary"
                    size="sm"
                    @click="markAllRead"
                >
                    <Check class="size-4" />
                    {{ $t('Mark all read') }}
                </Button>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="notificationSettings()">
                        <Settings class="size-4" />
                        {{ $t('Settings') }}
                    </Link>
                </Button>
            </div>
        </header>

        <p
            v-if="props.notifications.data.length === 0"
            class="rounded-lg border border-border bg-surface px-4 py-12 text-center text-sm text-text-muted"
        >
            {{ $t('Nothing here yet.') }}
        </p>

        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="notification in props.notifications.data"
                :key="notification.id"
                class="flex items-start gap-3 rounded-lg border border-border bg-surface p-4"
                :class="{ 'border-l-2 border-l-accent': !notification.readAt }"
            >
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <component
                        :is="notification.url ? 'a' : 'span'"
                        :href="notification.url ?? undefined"
                        class="font-medium text-text"
                        :class="{ 'hover:underline': notification.url }"
                    >
                        {{ notification.title }}
                    </component>
                    <p class="text-sm text-text-muted">
                        {{ plainBody(notification.body) }}
                    </p>
                    <p class="text-xs text-text-muted">
                        {{ formatDateTime(notification.createdAt) }}
                    </p>
                </div>
                <Button
                    v-if="!notification.readAt"
                    variant="ghost"
                    size="icon"
                    :aria-label="
                        $t('Mark “:title” as read', {
                            title: notification.title,
                        })
                    "
                    @click="markRead(notification)"
                >
                    <Check class="size-4" />
                </Button>
            </li>
        </ul>

        <nav
            v-if="props.notifications.last_page > 1"
            class="flex items-center justify-between"
            :aria-label="$t('Notification pages')"
        >
            <Button
                variant="outline"
                size="sm"
                :disabled="props.notifications.current_page <= 1"
                @click="goToPage(props.notifications.current_page - 1)"
            >
                {{ $t('Previous') }}
            </Button>
            <span class="text-sm text-text-muted">
                {{
                    $t('Showing :from–:to of :total', {
                        from: String(props.notifications.from ?? 0),
                        to: String(props.notifications.to ?? 0),
                        total: String(props.notifications.total),
                    })
                }}
            </span>
            <Button
                variant="outline"
                size="sm"
                :disabled="
                    props.notifications.current_page >=
                    props.notifications.last_page
                "
                @click="goToPage(props.notifications.current_page + 1)"
            >
                {{ $t('Next') }}
            </Button>
        </nav>
    </div>
</template>

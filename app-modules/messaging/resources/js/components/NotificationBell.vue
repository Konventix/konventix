<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { Bell } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { edit as notificationSettings } from '@/routes/notification-preferences';
import {
    index as notificationsIndex,
    read as notificationsRead,
    readAll as notificationsReadAll,
} from '@/routes/notifications';

/**
 * The in-app inbox bell (ADR-0041 point 6). Lives in the persistent layout,
 * so the fetch below runs once per browser page load — never per Inertia
 * visit (lessons-from-lancore #5) — and `notification.received` pushes over
 * Reverb keep it current in between.
 */
/**
 * A mutable copy of the generated shape. laravel-data emits every field
 * `readonly`, which is right for a server payload and wrong for this list:
 * marking a notification read updates it in place and lets the request catch
 * up afterwards.
 */
type Notification = {
    -readonly [
        K in keyof Konventix.Messaging.Data.NotificationData
    ]: Konventix.Messaging.Data.NotificationData[K];
};

const page = usePage();
// Converted rather than cast: the shared auth prop types the id as a number,
// while every id in this application is a ULID string (ADR-0014).
const userId = computed<string | undefined>(() => {
    const id = page.props.auth?.user?.id;

    return id === undefined || id === null ? undefined : String(id);
});

const notifications = ref<Notification[]>([]);
const unread = ref(0);

async function fetchJson(
    url: string,
    method: 'get' | 'post' = 'get',
): Promise<unknown> {
    const xsrf = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(method === 'post' && xsrf
                ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
                : {}),
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`Notification request failed: ${response.status}`);
    }

    return response.json();
}

onMounted(async () => {
    if (!userId.value) {
        return;
    }

    try {
        const data = (await fetchJson(notificationsIndex().url)) as {
            unread: number;
            notifications: Notification[];
        };
        unread.value = data.unread;
        notifications.value = data.notifications;
    } catch {
        // The bell is decoration on every page it renders on — a failed
        // fetch must never break the page around it.
    }
});

useEcho<Notification>(
    `Konventix.Identity.Models.User.${userId.value ?? ''}`,
    '.notification.received',
    (incoming) => {
        notifications.value = [incoming, ...notifications.value].slice(0, 15);
        unread.value += 1;
    },
);

async function markRead(notification: Notification): Promise<void> {
    if (notification.readAt) {
        return;
    }

    notification.readAt = new Date().toISOString();
    unread.value = Math.max(0, unread.value - 1);

    try {
        await fetchJson(
            notificationsRead({ notification: notification.id }).url,
            'post',
        );
    } catch {
        // Optimistically marked; the server state catches up on next load.
    }
}

async function markAllRead(): Promise<void> {
    notifications.value.forEach((notification) => {
        notification.readAt = notification.readAt ?? new Date().toISOString();
    });
    unread.value = 0;

    try {
        await fetchJson(notificationsReadAll().url, 'post');
    } catch {
        // See markRead.
    }
}

function open(notification: Notification): void {
    void markRead(notification);

    if (notification.url) {
        window.location.href = notification.url;
    }
}
</script>

<template>
    <DropdownMenu v-if="userId">
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="relative"
                aria-label="Notifications"
            >
                <Bell class="size-5" />
                <span
                    v-if="unread > 0"
                    class="text-accent-foreground absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-accent px-1 text-[10px] leading-none font-medium"
                    role="status"
                >
                    {{ unread > 9 ? '9+' : unread }}
                </span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80 p-0">
            <div
                class="flex items-center justify-between border-b border-border px-3 py-2"
            >
                <span class="text-sm font-medium">Notifications</span>
                <Button
                    v-if="unread > 0"
                    variant="ghost"
                    size="sm"
                    class="h-auto px-2 py-1 text-xs"
                    @click="markAllRead"
                >
                    Mark all read
                </Button>
            </div>
            <div class="max-h-96 overflow-y-auto">
                <p
                    v-if="notifications.length === 0"
                    class="px-3 py-6 text-center text-sm text-text-muted"
                >
                    Nothing here yet.
                </p>
                <button
                    v-for="notification in notifications"
                    :key="notification.id"
                    type="button"
                    class="block w-full border-b border-border px-3 py-2 text-left last:border-b-0 hover:bg-subtle"
                    @click="open(notification)"
                >
                    <span class="flex items-start gap-2">
                        <span
                            class="mt-1.5 size-2 shrink-0 rounded-full"
                            :class="
                                notification.readAt
                                    ? 'bg-transparent'
                                    : 'bg-accent'
                            "
                        />
                        <span class="min-w-0">
                            <span
                                class="block truncate text-sm"
                                :class="
                                    notification.readAt
                                        ? 'text-text-muted'
                                        : 'font-medium'
                                "
                            >
                                {{ notification.title }}
                            </span>
                            <span
                                class="block truncate text-xs text-text-muted"
                                v-text="
                                    notification.body.replace(/<[^>]*>/g, '')
                                "
                            />
                        </span>
                    </span>
                </button>
            </div>
            <div class="border-t border-border px-3 py-2 text-center">
                <Link
                    :href="notificationSettings()"
                    class="text-xs text-text-muted hover:text-text"
                >
                    Notification settings
                </Link>
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
</template>

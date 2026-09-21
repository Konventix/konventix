<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell, Check } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { edit as notificationSettings } from '@/routes/notification-preferences';
import { index as notificationsIndex } from '@/routes/notifications';
import { badgeText, bellLabel, inboxView } from './notificationInbox';
import { useNotificationInbox } from './useNotificationInbox';

/**
 * The in-app inbox bell (ADR-0041 point 6). A view of the page's one inbox
 * (`useNotificationInbox`): the brand shell mounts it twice, mobile and
 * desktop, and both must show the same list and the same count (#294).
 *
 * `shell` picks the trigger's look, because the bell is the one control the
 * two shells share: in the brand bar its neighbours are the round, bordered
 * menu button and account trigger; in the console header it sits next to
 * the `SidebarTrigger`, a ghost button of the same 28px.
 */
withDefaults(
    defineProps<{
        shell?: 'brand' | 'console';
    }>(),
    { shell: 'brand' },
);

const page = usePage();
// The shared auth prop already types the id as a ULID string (ADR-0014,
// ADR-0051 §5) — this stays a conversion rather than a cast for the one
// case that isn't a string: a signed-out visitor, where `user` is null.
const userId = computed<string | undefined>(() => {
    const id = page.props.auth?.user?.id;

    return id === undefined || id === null ? undefined : String(id);
});

// The current brand's Reverb app key, shared by `HandleInertiaRequests`
// (ADR-0025); `null` on the operator console host, which has no brand and
// therefore no realtime — the feed still loads there, the socket is skipped.
// Typed by hand because the shared-props declaration in `resources/js/types`
// carries this key only through its index signature.
const reverbKey = computed<string | null>(() => {
    const reverb = page.props.reverb as { key?: string | null } | undefined;

    return reverb?.key ?? null;
});

const { inbox, markRead, markAllRead, reload } = useNotificationInbox(
    userId,
    reverbKey,
);

const view = computed(() => inboxView(inbox));

/**
 * A notification's URL is a link into this brand, so it stays an Inertia
 * visit — a full load throws the console's whole SPA away for one click. A
 * URL on another origin (a brand's mail can carry one) still navigates.
 */
function open(notification: { id: string; url: string | null }): void {
    void markRead(notification.id);

    if (!notification.url) {
        return;
    }

    const target = new URL(notification.url, window.location.href);

    if (target.origin === window.location.origin) {
        router.visit(target.pathname + target.search + target.hash);
    } else {
        window.location.href = notification.url;
    }
}
</script>

<template>
    <DropdownMenu v-if="userId">
        <DropdownMenuTrigger as-child>
            <button
                v-if="shell === 'brand'"
                type="button"
                class="relative inline-flex size-9 items-center justify-center rounded-full border border-border bg-surface text-text-muted transition hover:bg-surface-muted hover:text-text"
                :aria-label="bellLabel(inbox.unread)"
            >
                <Bell class="size-4" />
                <span
                    v-if="inbox.unread > 0"
                    aria-hidden="true"
                    class="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-accent px-1 text-[10px] leading-none font-medium text-on-accent"
                >
                    {{ badgeText(inbox.unread) }}
                </span>
            </button>
            <Button
                v-else
                variant="ghost"
                size="icon"
                class="relative h-7 w-7"
                :aria-label="bellLabel(inbox.unread)"
            >
                <Bell class="size-4" />
                <span
                    v-if="inbox.unread > 0"
                    aria-hidden="true"
                    class="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-accent px-1 text-[10px] leading-none font-medium text-on-accent"
                >
                    {{ badgeText(inbox.unread) }}
                </span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80 p-0">
            <div
                class="flex items-center justify-between border-b border-border px-3 py-2"
            >
                <span class="text-sm font-medium">Notifications</span>
                <Button
                    v-if="inbox.unread > 0"
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
                    v-if="view === 'loading'"
                    class="px-3 py-6 text-center text-sm text-text-muted"
                    role="status"
                >
                    Loading…
                </p>
                <div
                    v-else-if="view === 'failed'"
                    class="flex flex-col items-center gap-2 px-3 py-6 text-center"
                >
                    <p class="text-sm text-danger" role="alert">
                        Notifications could not be loaded.
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="h-auto px-3 py-1 text-xs"
                        @click="reload"
                    >
                        Retry
                    </Button>
                </div>
                <p
                    v-else-if="view === 'empty'"
                    class="px-3 py-6 text-center text-sm text-text-muted"
                >
                    Nothing here yet.
                </p>
                <div
                    v-for="notification in inbox.notifications"
                    :key="notification.id"
                    class="flex items-start gap-1 border-b border-border last:border-b-0 hover:bg-subtle"
                >
                    <button
                        type="button"
                        class="min-w-0 flex-1 px-3 py-2 text-left"
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
                                        notification.body.replace(
                                            /<[^>]*>/g,
                                            '',
                                        )
                                    "
                                />
                            </span>
                        </span>
                    </button>
                    <!--
                        Dismissing one entry without opening it. Icon-only, so
                        it carries its own accessible name (BFSG/WCAG 4.1.2)
                        and names WHICH notification it clears — a list of
                        twenty identical "Mark as read" buttons tells a screen
                        reader user nothing.
                    -->
                    <button
                        v-if="!notification.readAt"
                        type="button"
                        class="mt-1.5 mr-2 inline-flex size-7 shrink-0 items-center justify-center rounded-md text-text-muted transition hover:bg-surface-muted hover:text-text"
                        :aria-label="`Mark &quot;${notification.title}&quot; as read`"
                        @click="markRead(notification.id)"
                    >
                        <Check class="size-4" />
                    </button>
                </div>
            </div>
            <div
                class="flex items-center justify-between border-t border-border px-3 py-2"
            >
                <Link
                    :href="notificationsIndex()"
                    class="text-xs font-medium text-text hover:underline"
                >
                    All notifications
                </Link>
                <Link
                    :href="notificationSettings()"
                    class="text-xs text-text-muted hover:text-text"
                >
                    Settings
                </Link>
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
</template>

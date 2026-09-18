import { echo } from '@laravel/echo-vue';
import { reactive, readonly, watch, type Ref } from 'vue';
import {
    feed as notificationsFeed,
    read as notificationsRead,
    readAll as notificationsReadAll,
} from '@/routes/notifications';
import {
    applyFeed,
    emptyInbox,
    markAllRead as applyMarkAllRead,
    markRead as applyMarkRead,
    receive,
    type InboxNotification,
    type InboxState,
} from './notificationInbox';

/**
 * One inbox per browser page (#294, ADR-0041 point 6). Module-scoped on
 * purpose: the persistent layout mounts the bell more than once, and every
 * mount must see the same list. The feed is fetched once per signed-in
 * person — never per Inertia visit (lessons-from-lancore #5) — and the
 * `notification.received` push over Reverb keeps it current in between.
 *
 * The subscription follows the account, not the component: a bell that
 * unmounts (the console shell replacing the brand shell) must not tear down
 * the channel the other bell still reads from, and a guest subscribes to
 * nothing at all — a private channel with an empty id is a 403 from
 * `/broadcasting/auth` on every logged-out page. Signing out and in as
 * someone else in the same tab starts the inbox over.
 */
const state = reactive<InboxState>(emptyInbox());

let subscribedTo: string | null = null;

const channelFor = (userId: string): string =>
    `Konventix.Identity.Models.User.${userId}`;

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

function unsubscribe(): void {
    if (subscribedTo !== null) {
        echo().leave(channelFor(subscribedTo));
        subscribedTo = null;
    }
}

function follow(userId: string | null): void {
    // The SSR build renders the bell's markup and nothing else: no socket,
    // no fetch — the browser does both once it takes over.
    if (typeof window === 'undefined' || userId === state.ownerId) {
        return;
    }

    unsubscribe();
    Object.assign(state, emptyInbox(userId));

    if (userId === null) {
        return;
    }

    echo()
        .private(channelFor(userId))
        .listen('.notification.received', (incoming: InboxNotification) => {
            receive(state, incoming);
        });
    subscribedTo = userId;

    void fetchJson(notificationsFeed().url)
        .then((data) => {
            // The account may have changed while the request was in flight.
            if (state.ownerId === userId) {
                applyFeed(
                    state,
                    data as {
                        unread: number;
                        notifications: InboxNotification[];
                    },
                );
            }
        })
        .catch(() => {
            // The bell is decoration on every page it renders on — a failed
            // fetch must never break the page around it. `loaded` stays
            // false, so the bell shows its loading state rather than an
            // empty inbox that is not known to be empty.
        });
}

async function markRead(id: string): Promise<void> {
    if (!applyMarkRead(state, id)) {
        return;
    }

    try {
        await fetchJson(notificationsRead({ notification: id }).url, 'post');
    } catch {
        // Optimistically marked; the server state catches up on next load.
    }
}

async function markAllRead(): Promise<void> {
    applyMarkAllRead(state);

    try {
        await fetchJson(notificationsReadAll().url, 'post');
    } catch {
        // See markRead.
    }
}

/**
 * `userId` is the shared auth prop's id, `undefined` for a guest. Reactive
 * so the inbox tracks a sign-in or sign-out that happens without a full
 * page load.
 */
export function useNotificationInbox(userId: Ref<string | undefined>) {
    watch(userId, (id) => follow(id ?? null), { immediate: true });

    return {
        inbox: readonly(state),
        markRead,
        markAllRead,
    };
}

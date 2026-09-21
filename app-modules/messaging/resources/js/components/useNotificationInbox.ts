import { echo } from '@laravel/echo-vue';
import type { Ref } from 'vue';
import { reactive, readonly, watch } from 'vue';
import {
    feed as notificationsFeed,
    read as notificationsRead,
    readAll as notificationsReadAll,
} from '@/routes/notifications';
import type { InboxNotification, InboxState } from './notificationInbox';
import {
    applyFeed,
    emptyInbox,
    markAllRead as applyMarkAllRead,
    markRead as applyMarkRead,
    receive,
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

/**
 * The request shape every inbox call shares. The URL is never a parameter
 * here: each caller hands `fetch()` the Wayfinder route directly, so what is
 * fetched is always one of the three named routes and nothing a value could
 * steer.
 */
function requestInit(method: 'get' | 'post'): RequestInit {
    const xsrf = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    return {
        method,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(method === 'post' && xsrf
                ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
                : {}),
        },
        credentials: 'same-origin',
    };
}

async function parseJson(response: Response): Promise<unknown> {
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

    // A browser fetch of a fixed same-origin Wayfinder route. The advanced
    // SAST analyzer traces the XSRF cookie from `requestInit()` into the
    // request headers and reports the call as a server-side request with a
    // user-controlled URL (v0.6.4, pipeline 1828); there is no server and
    // no URL a value could steer, so the finding is excluded on the three
    // fetch lines and nowhere else. The tag is unscoped on purpose: the
    // analyzer (2.55.0) matched none of the rule ids its own report names
    // for this finding, and GitLab documents the ids as unstable.
    // gitlab-advanced-sast-exclude
    void fetch(notificationsFeed().url, requestInit('get'))
        .then(parseJson)
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
        // See the feed fetch above.
        // gitlab-advanced-sast-exclude
        await fetch(
            notificationsRead({ notification: id }).url,
            requestInit('post'),
        ).then(parseJson);
    } catch {
        // Optimistically marked; the server state catches up on next load.
    }
}

async function markAllRead(): Promise<void> {
    applyMarkAllRead(state);

    try {
        // See the feed fetch above.
        // gitlab-advanced-sast-exclude
        await fetch(notificationsReadAll().url, requestInit('post')).then(
            parseJson,
        );
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

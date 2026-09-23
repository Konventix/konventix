import { transChoice } from 'laravel-vue-i18n';

/**
 * The bell's state, once per browser page (#294).
 *
 * The bell renders from two places in the brand shell — `AppTopbar.vue`
 * mounts it in the mobile block and again in the desktop block, and only CSS
 * decides which one is visible — so state on the component meant two feed
 * fetches per page load, two Echo subscriptions and two counters that drifted
 * apart the moment one of them marked something read. The state lives here
 * instead, module-scoped: the fetch and the subscription happen once per
 * signed-in person, and every bell is a view of the same list.
 *
 * The transitions are plain functions over a plain shape so vitest covers
 * them without Vue (`notificationInbox.test.ts`); the reactive wrapper and
 * the network are `useNotificationInbox.ts`'s business.
 */

/**
 * A mutable copy of the generated shape. laravel-data emits every field
 * `readonly`, which is right for a server payload and wrong for this list:
 * marking a notification read updates it in place and lets the request catch
 * up afterwards.
 */
export type InboxNotification = {
    -readonly [
        K in keyof Konventix.Messaging.Data.NotificationData
    ]: Konventix.Messaging.Data.NotificationData[K];
};

export type InboxState = {
    /** Who the list belongs to; a different id means "start over". */
    ownerId: string | null;
    /** `false` until the first feed response landed — the bell's loading state. */
    loaded: boolean;
    /**
     * The last feed fetch was rejected or answered non-2xx. A terminal state
     * beside `loaded`, not a substitute for it: before this existed every
     * failure — a 419 after the session expired, a 500, a lost connection —
     * left `loaded` false and the bell said "Loading…" until the next full
     * page load. `retryFeed()` clears it before the next attempt.
     */
    failed: boolean;
    notifications: InboxNotification[];
    unread: number;
};

/** What the bell's list area shows, decided once here rather than in the template. */
export type InboxView = 'loading' | 'failed' | 'empty' | 'list';

/** The bell's short list keeps this many; the `/notifications` page has the rest. */
export const INBOX_LIMIT = 15;

export function emptyInbox(ownerId: string | null = null): InboxState {
    return {
        ownerId,
        loaded: false,
        failed: false,
        notifications: [],
        unread: 0,
    };
}

export function applyFeed(
    state: InboxState,
    feed: { unread: number; notifications: InboxNotification[] },
): void {
    state.notifications = feed.notifications.slice(0, INBOX_LIMIT);
    state.unread = feed.unread;
    state.loaded = true;
    state.failed = false;
}

/** The feed request did not land: rejected, or answered with a non-ok status. */
export function failFeed(state: InboxState): void {
    state.failed = true;
}

/**
 * Back to "Loading…" for the retry. Only the flag moves: a list that a
 * push already filled stays where it is until the feed answers.
 */
export function retryFeed(state: InboxState): void {
    state.failed = false;
}

/**
 * "Loading…" only while neither terminal state was reached; a failure
 * after a successful load (a retry cannot cause one today, but a refresh
 * could) keeps the list rather than hiding it behind the error line.
 */
export function inboxView(
    state: Pick<Readonly<InboxState>, 'loaded' | 'failed'> & {
        readonly notifications: readonly unknown[];
    },
): InboxView {
    if (state.loaded) {
        return state.notifications.length === 0 ? 'empty' : 'list';
    }

    return state.failed ? 'failed' : 'loading';
}

/**
 * A `notification.received` push. The same id can arrive twice — a
 * reconnect replays, and the feed fetch may race the first push — so an
 * entry already in the list is not counted again.
 */
export function receive(state: InboxState, incoming: InboxNotification): void {
    if (state.notifications.some((entry) => entry.id === incoming.id)) {
        return;
    }

    state.notifications = [incoming, ...state.notifications].slice(
        0,
        INBOX_LIMIT,
    );
    state.unread += 1;
}

/** Returns whether anything changed — the caller only talks to the server then. */
export function markRead(
    state: InboxState,
    id: string,
    readAt: string = new Date().toISOString(),
): boolean {
    const entry = state.notifications.find((candidate) => candidate.id === id);

    if (!entry || entry.readAt) {
        return false;
    }

    entry.readAt = readAt;
    state.unread = Math.max(0, state.unread - 1);

    return true;
}

export function markAllRead(
    state: InboxState,
    readAt: string = new Date().toISOString(),
): void {
    state.notifications.forEach((entry) => {
        entry.readAt = entry.readAt ?? readAt;
    });
    state.unread = 0;
}

/**
 * The trigger's accessible name carries the count, the way the cart link
 * does ("Cart, 3 items"): an `aria-label` on the button overrides whatever
 * the badge inside it says, so without this a screen reader hears
 * "Notifications" at 0 and at 12 alike.
 */
export function bellLabel(unread: number): string {
    return transChoice(
        '{0} Notifications|{1} Notifications, :count unread|[2,*] Notifications, :count unread',
        unread,
    );
}

/** What the badge prints; the label above says the exact number. */
export function badgeText(unread: number): string {
    return unread > 9 ? '9+' : String(unread);
}

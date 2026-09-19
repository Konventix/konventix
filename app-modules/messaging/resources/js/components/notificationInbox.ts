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
    notifications: InboxNotification[];
    unread: number;
};

/** The bell's short list keeps this many; the `/notifications` page has the rest. */
export const INBOX_LIMIT = 15;

export function emptyInbox(ownerId: string | null = null): InboxState {
    return { ownerId, loaded: false, notifications: [], unread: 0 };
}

export function applyFeed(
    state: InboxState,
    feed: { unread: number; notifications: InboxNotification[] },
): void {
    state.notifications = feed.notifications.slice(0, INBOX_LIMIT);
    state.unread = feed.unread;
    state.loaded = true;
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
    if (unread === 0) {
        return 'Notifications';
    }

    return unread === 1
        ? 'Notifications, 1 unread'
        : `Notifications, ${unread} unread`;
}

/** What the badge prints; the label above says the exact number. */
export function badgeText(unread: number): string {
    return unread > 9 ? '9+' : String(unread);
}

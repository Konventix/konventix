import { describe, expect, it } from 'vitest';
import {
    applyFeed,
    badgeText,
    bellLabel,
    emptyInbox,
    INBOX_LIMIT,
    markAllRead,
    markRead,
    receive,
    type InboxNotification,
} from './notificationInbox';

function notification(
    id: string,
    readAt: string | null = null,
): InboxNotification {
    return {
        id,
        topicKey: null,
        title: `Notification ${id}`,
        body: '<p>body</p>',
        url: null,
        readAt,
        createdAt: '2026-09-18T10:00:00+00:00',
    };
}

describe('notificationInbox', () => {
    it('is not loaded until a feed lands, and then is', () => {
        const state = emptyInbox('u1');

        expect(state.loaded).toBe(false);

        applyFeed(state, { unread: 2, notifications: [notification('a')] });

        expect(state.loaded).toBe(true);
        expect(state.unread).toBe(2);
        expect(state.notifications.map((entry) => entry.id)).toEqual(['a']);
    });

    it('puts a push at the top, counts it once, and keeps the list short', () => {
        const state = emptyInbox('u1');
        applyFeed(state, {
            unread: 0,
            notifications: Array.from({ length: INBOX_LIMIT }, (_, index) =>
                notification(`n${index}`, '2026-09-18T09:00:00+00:00'),
            ),
        });

        receive(state, notification('fresh'));

        expect(state.notifications[0]?.id).toBe('fresh');
        expect(state.notifications).toHaveLength(INBOX_LIMIT);
        expect(state.unread).toBe(1);

        // A reconnect replays the same event; the feed fetch can race the
        // first push. Neither may count twice.
        receive(state, notification('fresh'));

        expect(state.unread).toBe(1);
        expect(
            state.notifications.filter((entry) => entry.id === 'fresh'),
        ).toHaveLength(1);
    });

    it('marks one read exactly once and never counts below zero', () => {
        const state = emptyInbox('u1');
        applyFeed(state, {
            unread: 1,
            notifications: [
                notification('a'),
                notification('b', '2026-09-18T09:00:00+00:00'),
            ],
        });

        expect(markRead(state, 'a', '2026-09-18T10:05:00+00:00')).toBe(true);
        expect(state.notifications[0]?.readAt).toBe(
            '2026-09-18T10:05:00+00:00',
        );
        expect(state.unread).toBe(0);

        // Already read, unknown id: nothing to tell the server.
        expect(markRead(state, 'a')).toBe(false);
        expect(markRead(state, 'b')).toBe(false);
        expect(markRead(state, 'missing')).toBe(false);
        expect(state.unread).toBe(0);
    });

    it('marks everything read but leaves an earlier readAt alone', () => {
        const state = emptyInbox('u1');
        applyFeed(state, {
            unread: 7,
            notifications: [
                notification('a'),
                notification('b', '2026-09-18T09:00:00+00:00'),
            ],
        });

        markAllRead(state, '2026-09-18T10:05:00+00:00');

        expect(state.unread).toBe(0);
        expect(state.notifications.map((entry) => entry.readAt)).toEqual([
            '2026-09-18T10:05:00+00:00',
            '2026-09-18T09:00:00+00:00',
        ]);
    });

    it('names the count in the accessible label the way the cart link does', () => {
        expect(bellLabel(0)).toBe('Notifications');
        expect(bellLabel(1)).toBe('Notifications, 1 unread');
        expect(bellLabel(12)).toBe('Notifications, 12 unread');
    });

    it('caps the badge but not the label', () => {
        expect(badgeText(9)).toBe('9');
        expect(badgeText(10)).toBe('9+');
        expect(bellLabel(10)).toBe('Notifications, 10 unread');
    });
});

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import type { InboxNotification } from './notificationInbox';

/**
 * The composable against a page whose realtime is broken or absent.
 *
 * Vitest runs in the `node` environment (`vitest.config.ts`) and there is
 * deliberately no DOM library: the composable only needs `window` to exist
 * (its SSR guard) and `document.cookie` to be a string, so both are stubbed
 * here rather than pulling jsdom in for two properties.
 *
 * `@laravel/echo-vue` is mocked so that `echo()` throws the way it does on
 * the operator console host, where `app.ts` finds no `reverb.key` and Echo
 * is never configured — the case that used to leave the bell on "Loading…"
 * for the life of the page.
 */
const echoMock = vi.hoisted(() => ({
    echo: vi.fn((): never => {
        throw new Error('Echo has not been configured');
    }),
}));

vi.mock('@laravel/echo-vue', () => echoMock);

// The URLs the bell would take from the Wayfinder helpers; only their
// presence matters here, the fetch is stubbed.
const endpoints = {
    feed: '/notifications/feed',
    read: (id: string) => `/notifications/${id}/read`,
    readAll: '/notifications/read-all',
};

function notification(id: string): InboxNotification {
    return {
        id,
        topicKey: null,
        title: `Notification ${id}`,
        body: '<p>body</p>',
        url: null,
        readAt: null,
        createdAt: '2026-09-21T10:00:00+00:00',
    };
}

function okResponse(body: unknown): Response {
    return {
        ok: true,
        status: 200,
        json: () => Promise.resolve(body),
    } as unknown as Response;
}

function failedResponse(status: number): Response {
    return {
        ok: false,
        status,
        json: () => Promise.resolve({}),
    } as unknown as Response;
}

const fetchMock = vi.fn<typeof fetch>();

/** A fresh module per test: the inbox state is module-scoped on purpose. */
async function loadComposable() {
    vi.resetModules();

    return (await import('./useNotificationInbox')).useNotificationInbox;
}

describe('useNotificationInbox', () => {
    beforeEach(() => {
        vi.stubGlobal('window', {});
        // `documentElement` because laravel-vue-i18n (the bell's label,
        // ADR-0068) reads the page language when it is first imported.
        vi.stubGlobal('document', {
            cookie: 'XSRF-TOKEN=token',
            documentElement: { lang: 'en' },
        });
        vi.stubGlobal('fetch', fetchMock);
        fetchMock.mockReset();
        echoMock.echo.mockClear();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('loads the feed even though Echo throws', async () => {
        fetchMock.mockResolvedValue(
            okResponse({ unread: 1, notifications: [notification('a')] }),
        );
        const useNotificationInbox = await loadComposable();

        const { inbox } = useNotificationInbox(
            ref('u1'),
            ref('brand-key'),
            endpoints,
        );

        await vi.waitFor(() => expect(inbox.loaded).toBe(true));

        expect(echoMock.echo).toHaveBeenCalled();
        expect(inbox.failed).toBe(false);
        expect(inbox.unread).toBe(1);
        expect(inbox.notifications.map((entry) => entry.id)).toEqual(['a']);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('never touches Echo when the page has no Reverb key', async () => {
        fetchMock.mockResolvedValue(
            okResponse({ unread: 0, notifications: [] }),
        );
        const useNotificationInbox = await loadComposable();

        const { inbox } = useNotificationInbox(ref('u1'), ref(null), endpoints);

        await vi.waitFor(() => expect(inbox.loaded).toBe(true));

        expect(echoMock.echo).not.toHaveBeenCalled();
        expect(inbox.notifications).toEqual([]);
    });

    it('fetches before it subscribes', async () => {
        const order: string[] = [];
        fetchMock.mockImplementation(() => {
            order.push('fetch');

            return Promise.resolve(
                okResponse({ unread: 0, notifications: [] }),
            );
        });
        echoMock.echo.mockImplementation(() => {
            order.push('echo');

            throw new Error('Echo has not been configured');
        });
        const useNotificationInbox = await loadComposable();

        const { inbox } = useNotificationInbox(
            ref('u1'),
            ref('brand-key'),
            endpoints,
        );

        await vi.waitFor(() => expect(inbox.loaded).toBe(true));

        expect(order).toEqual(['fetch', 'echo']);
    });

    it('ends in failed, not endless loading, when the fetch is rejected', async () => {
        fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));
        const useNotificationInbox = await loadComposable();

        const { inbox } = useNotificationInbox(ref('u1'), ref(null), endpoints);

        await vi.waitFor(() => expect(inbox.failed).toBe(true));

        expect(inbox.loaded).toBe(false);
    });

    it('treats a non-ok answer as a failure too', async () => {
        fetchMock.mockResolvedValue(failedResponse(419));
        const useNotificationInbox = await loadComposable();

        const { inbox } = useNotificationInbox(ref('u1'), ref(null), endpoints);

        await vi.waitFor(() => expect(inbox.failed).toBe(true));

        expect(inbox.loaded).toBe(false);
    });

    it('reloads the feed on retry and recovers', async () => {
        fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));
        const useNotificationInbox = await loadComposable();

        const { inbox, reload } = useNotificationInbox(
            ref('u1'),
            ref(null),
            endpoints,
        );

        await vi.waitFor(() => expect(inbox.failed).toBe(true));

        fetchMock.mockResolvedValueOnce(
            okResponse({ unread: 2, notifications: [notification('b')] }),
        );

        await reload();

        expect(inbox.failed).toBe(false);
        expect(inbox.loaded).toBe(true);
        expect(inbox.unread).toBe(2);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('fetches nothing for a guest', async () => {
        const useNotificationInbox = await loadComposable();

        const { inbox } = useNotificationInbox(
            ref(undefined),
            ref(null),
            endpoints,
        );

        expect(inbox.loaded).toBe(false);
        expect(inbox.failed).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();
        expect(echoMock.echo).not.toHaveBeenCalled();
    });
});

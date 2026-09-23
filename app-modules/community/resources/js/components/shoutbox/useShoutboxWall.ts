import { usePage } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import type { PendingLine, WallEntry, WallLine, WallTombstone } from './wall';
import { mergeAll, newestId, oldestId, remove, upsert } from './wall';

/**
 * The live half of the shoutbox wall (design §8), shared by the hub card and
 * the room page: the private room channel, the reconciliation after a
 * dropped socket, the optimistic compose box and the refusal countdown.
 *
 * **The channel is the notification bell's path** (`useNotificationInbox`):
 * `echo().private(…)` on the brand's Reverb app, skipped where there is no
 * key, and guarded so a page whose realtime is broken still shows the lines
 * it was rendered with.
 *
 * **A dropped socket must not freeze the wall silently.** Every time the
 * room channel reports `subscribed` — the first subscription, which catches
 * what was posted between the server render and the subscription even when
 * the socket itself was already open (the bell opens it on every page), and
 * every re-subscription pusher makes after a reconnect — the wall asks for
 * everything after the newest line it holds, plus every removal since its
 * last answer (`syncedAt`), so a line it already showed does not survive a
 * removal it missed. Removals come back in the broadcast's own tombstone
 * shape. A mount onto a channel another holder already subscribed catches
 * up once by itself, since `subscribed` will not fire again for it.
 * Overlapping requests for a catch-up collapse into one run plus at most one
 * follow-up.
 *
 * **Optimistic, then reconciled** (design §8): a sent line shows at once as
 * pending; the server's answer replaces it with the real line, the
 * broadcast of the same id changes nothing further, and a refusal rolls it
 * back with a toast. The sentence of a refusal is the server's
 * (`ShoutboxWriteException`); this file only reads the fields next to it.
 *
 * The URLs are handed in: the hub card may not import a route helper
 * (`tests/Feature/HubComponentBoundaryTest.php`), so the card takes them from
 * its payload and the room page from Wayfinder.
 */
export type WallEndpoints = {
    messages: string;
    post: string;
};

type Feed = Konventix.Community.Data.ShoutboxFeedData;

/** What a refused post answers with — see `ShoutboxWriteException::toJsonResponse()`. */
type Refusal = {
    message?: string;
    errors?: Record<string, string[] | undefined>;
    cause?: Konventix.Community.Enums.ShoutboxRefusal;
    retryAfterSeconds?: number | null;
};

/** Refusals that hold for a while, rather than being about this one line. */
const HOLDING: ReadonlyArray<Konventix.Community.Enums.ShoutboxRefusal> = [
    'muted',
    'too_many_messages',
    'room_locked',
    'room_closed',
];

/** The smallest ULID: "everything" for a wall that held nothing yet. */
const FIRST_ULID = '00000000000000000000000000';

/** A catch-up that keeps finding more stops here and waits for the next reconnect. */
const MAX_CATCH_UP_PAGES = 10;

/**
 * Channels are shared per page: the card and the room are never mounted
 * together today, but Inertia may mount the next page before it unmounts
 * the last one, and `echo().leave()` would then close the channel under the
 * new page's feet. Counting holders makes the last one out the one that
 * leaves.
 */
const holders = new Map<string, number>();

function channelFor(roomId: string): string {
    return `community.room.${roomId}`;
}

/** The request shape every shoutbox call shares — the bell's `requestInit()`. */
export function shoutboxRequest(
    method: 'get' | 'post' | 'delete',
    body?: Record<string, unknown>,
    keepalive = false,
): RequestInit {
    const xsrf = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    return {
        method,
        keepalive,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
            ...(method !== 'get' && xsrf
                ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
                : {}),
        },
        credentials: 'same-origin',
        ...(body ? { body: JSON.stringify(body) } : {}),
    };
}

function withQuery(url: string, query: Record<string, string>): string {
    const target = new URL(url, window.location.origin);

    for (const [key, value] of Object.entries(query)) {
        target.searchParams.set(key, value);
    }

    return target.toString();
}

export function useShoutboxWall(
    wall: Konventix.Community.Data.ShoutboxWallData,
    endpoints: WallEndpoints,
) {
    const page = usePage();

    const entries = ref<WallEntry[]>([...wall.lines]);
    const pending = ref<PendingLine[]>([]);
    const hasOlder = ref(wall.hasOlder);
    const loadingOlder = ref(false);

    /** The sentence under the compose box; the viewer's mute when the page opened. */
    const refusal = ref<string | null>(wall.mute);
    /** Whether the compose box is held shut by a refusal that lasts. */
    const held = ref(wall.mute !== null);
    /** Seconds until a timed hold lifts; null while none is counting. */
    const remaining = ref<number | null>(wall.retryAfterSeconds);

    let countdown: ReturnType<typeof setInterval> | null = null;
    let sequence = 0;

    const canWrite = computed(() => wall.notice === null && !held.value);

    function stopCountdown(): void {
        if (countdown !== null) {
            clearInterval(countdown);
            countdown = null;
        }
    }

    function hold(seconds: number | null): void {
        stopCountdown();
        held.value = true;
        remaining.value = seconds;

        if (seconds === null) {
            return;
        }

        countdown = setInterval(() => {
            remaining.value = Math.max(0, (remaining.value ?? 0) - 1);

            if (remaining.value === 0) {
                stopCountdown();
                held.value = false;
                remaining.value = null;
                refusal.value = null;
            }
        }, 1000);
    }

    function refuse(body: Refusal | null): void {
        const message =
            body?.errors?.body?.[0] ??
            body?.message ??
            trans('Your message could not be sent.');

        refusal.value = message;
        toast.error(message);

        if (body?.cause !== undefined && HOLDING.includes(body.cause)) {
            hold(body.retryAfterSeconds ?? null);
        }
    }

    /**
     * Sends one line. Resolves false when it did not land, so the compose
     * box can hand the text back rather than lose it.
     */
    async function post(text: string): Promise<boolean> {
        const body = text.trim();

        if (body === '' || !canWrite.value) {
            return false;
        }

        const key = `pending-${++sequence}`;
        pending.value = [...pending.value, { key, body }];

        try {
            // A browser fetch of a same-origin route the server handed over;
            // nothing a value typed here could steer. See the bell's note on
            // the advanced SAST analyzer, which reports this shape.
            // gitlab-advanced-sast-exclude
            const response = await fetch(
                endpoints.post,
                shoutboxRequest('post', { body }),
            );
            const payload: unknown = await response.json().catch(() => null);

            if (!response.ok) {
                refuse(payload as Refusal | null);

                return false;
            }

            entries.value = upsert(entries.value, payload as WallLine);
            refusal.value = null;

            return true;
        } catch {
            refuse(null);

            return false;
        } finally {
            pending.value = pending.value.filter((line) => line.key !== key);
        }
    }

    async function fetchFeed(query: Record<string, string>): Promise<Feed> {
        // See `post()` above.
        // gitlab-advanced-sast-exclude
        const response = await fetch(
            withQuery(endpoints.messages, query),
            shoutboxRequest('get'),
        );

        if (!response.ok) {
            throw new Error(`Shoutbox feed failed: ${response.status}`);
        }

        return (await response.json()) as Feed;
    }

    /** The server's instant for the last answer — what `since` asks from. */
    let syncedAt = wall.syncedAt;
    let catchingUp = false;
    let catchUpAgain = false;

    /**
     * Everything since the newest line held, and every removal since the
     * last answer. The first page's `syncedAt` becomes the next `since`:
     * later pages only carry newer lines, and a removal they report again
     * is applied twice to no effect.
     */
    async function runCatchUp(): Promise<void> {
        const since = syncedAt;
        let nextSyncedAt: string | null = null;

        for (let pageCount = 0; pageCount < MAX_CATCH_UP_PAGES; pageCount++) {
            const feed = await fetchFeed({
                after: newestId(entries.value) ?? FIRST_ULID,
                since,
            });

            nextSyncedAt ??= feed.syncedAt;
            entries.value = feed.removed.reduce(
                remove,
                mergeAll(entries.value, feed.lines),
            );

            if (!feed.hasMore) {
                break;
            }
        }

        if (nextSyncedAt !== null) {
            syncedAt = nextSyncedAt;
        }
    }

    async function catchUp(): Promise<void> {
        if (catchingUp) {
            catchUpAgain = true;

            return;
        }

        catchingUp = true;

        try {
            do {
                catchUpAgain = false;
                await runCatchUp();
            } while (catchUpAgain);
        } catch {
            // The lines already on screen stand; the next subscription asks again.
        } finally {
            catchingUp = false;
        }
    }

    /** One page above the oldest line held — scrolling upward. */
    async function loadOlder(): Promise<void> {
        const before = oldestId(entries.value);

        if (!hasOlder.value || loadingOlder.value || before === null) {
            return;
        }

        loadingOlder.value = true;

        try {
            const feed = await fetchFeed({ before });
            entries.value = mergeAll(entries.value, feed.lines);
            hasOlder.value = feed.hasMore;
        } catch {
            toast.error(trans('Older messages could not be loaded.'));
        } finally {
            loadingOlder.value = false;
        }
    }

    const onPosted = (line: WallLine): void => {
        entries.value = upsert(entries.value, line);
    };

    const onRemoved = (tombstone: WallTombstone): void => {
        entries.value = remove(entries.value, tombstone);
    };

    /** False once unmounted: a shared channel keeps this callback. */
    let active = true;

    const onSubscribed = (): void => {
        if (active) {
            void catchUp();
        }
    };

    let subscribed = false;

    onMounted(() => {
        if (remaining.value !== null) {
            hold(remaining.value);
        }

        const reverb = page.props.reverb as { key?: string | null } | undefined;

        if (!reverb?.key) {
            return;
        }

        const name = channelFor(wall.roomId);

        const alreadyHeld = (holders.get(name) ?? 0) > 0;

        try {
            echo()
                .private(name)
                .subscribed(onSubscribed)
                .listen('.shoutbox.message.posted', onPosted)
                .listen('.shoutbox.message.removed', onRemoved);
            holders.set(name, (holders.get(name) ?? 0) + 1);
            subscribed = true;
        } catch {
            // No push on this page; the rendered lines stand.
        }

        if (alreadyHeld) {
            void catchUp();
        }
    });

    onBeforeUnmount(() => {
        active = false;
        stopCountdown();

        if (!subscribed) {
            return;
        }

        const name = channelFor(wall.roomId);
        const left = (holders.get(name) ?? 1) - 1;

        try {
            if (left <= 0) {
                holders.delete(name);
                echo().leave(name);
            } else {
                holders.set(name, left);
                echo()
                    .private(name)
                    .stopListening('.shoutbox.message.posted', onPosted)
                    .stopListening('.shoutbox.message.removed', onRemoved);
            }
        } catch {
            // Nothing to leave.
        }
    });

    return {
        entries,
        pending,
        hasOlder,
        loadingOlder,
        refusal,
        remaining,
        canWrite,
        post,
        loadOlder,
    };
}

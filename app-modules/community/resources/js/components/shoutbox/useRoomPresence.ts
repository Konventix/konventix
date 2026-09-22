import { onBeforeUnmount, onMounted, ref } from 'vue';
import { shoutboxRequest } from './useShoutboxWall';

/**
 * The room page's heartbeat (design §5): "this account is connected and
 * looking at this room", every `BEAT_SECONDS`, and "away" as soon as the tab
 * is hidden. The server answers each beat with the presence list, so the
 * list refreshes on the same rhythm without a socket of its own.
 *
 * The beat is well inside `ConnectionState::ONLINE_SECONDS` (90), so an open
 * tab never decays to Away between two beats; a closed one expires on the
 * server's TTL even if the `leave` below never arrives.
 */
const BEAT_SECONDS = 60;

export type PresenceEndpoints = {
    beat: string;
    leave: string;
};

type Presence = Konventix.Community.Data.ShoutboxPresenceData;

export function useRoomPresence(
    initial: Presence[],
    endpoints: PresenceEndpoints,
) {
    const presence = ref<Presence[]>([...initial]);

    let timer: ReturnType<typeof setInterval> | null = null;

    async function beat(): Promise<void> {
        const status =
            document.visibilityState === 'hidden' ? 'away' : 'online';

        try {
            // A browser fetch of a fixed same-origin Wayfinder route; see
            // `useShoutboxWall.ts`.
            // gitlab-advanced-sast-exclude
            const response = await fetch(
                endpoints.beat,
                shoutboxRequest('post', { status }),
            );

            if (response.ok) {
                presence.value = (await response.json()) as Presence[];
            }
        } catch {
            // The list on screen stands until the next beat.
        }
    }

    function leave(): void {
        // `keepalive`, so the request survives the page going away.
        // gitlab-advanced-sast-exclude
        void fetch(
            endpoints.leave,
            shoutboxRequest('delete', undefined, true),
        ).catch(() => undefined);
    }

    const onVisibility = (): void => {
        void beat();
    };

    onMounted(() => {
        void beat();
        timer = setInterval(() => void beat(), BEAT_SECONDS * 1000);
        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('pagehide', leave);
    });

    onBeforeUnmount(() => {
        if (timer !== null) {
            clearInterval(timer);
        }

        document.removeEventListener('visibilitychange', onVisibility);
        window.removeEventListener('pagehide', leave);
        leave();
    });

    return { presence };
}

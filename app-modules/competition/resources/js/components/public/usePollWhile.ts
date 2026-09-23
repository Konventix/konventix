import { usePoll } from '@inertiajs/vue3';
import { onMounted, watch } from 'vue';

/**
 * Inertia polling while the server says so (design §10 "Freshness"): public
 * and participant pages reload their props every `pollSeconds` while a
 * competition is `Running`, and not at all otherwise. The server decides —
 * `pollSeconds` is null for anything that is not running — and the watch
 * stops the poll once a reload brings back null, so a finished bracket stops
 * asking.
 *
 * Started on mount only: there is nothing to poll during server rendering.
 */
export function usePollWhile(pollSeconds: () => number | null): void {
    const { start, stop } = usePoll(
        (pollSeconds() ?? 30) * 1000,
        {},
        { autoStart: false, keepAlive: false },
    );

    onMounted(() => {
        watch(pollSeconds, (seconds) => (seconds === null ? stop() : start()), {
            immediate: true,
        });
    });
}

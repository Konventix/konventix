import { useHttp } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * The picker's live availability (design §7.4): a poll every 15 s through
 * `useHttp` against the JSON endpoint while the tab is visible, into one
 * reactive map the scene and the list both read. Split out of `Pick.vue`
 * in the 2026-09-21 bugfix round so the page stays near the project's
 * "extract before ~300 lines" guideline once the Accessibility toggle
 * joined it; the behaviour is unchanged. `enabled` false (an assign-mode
 * claim renders no picker to keep fresh, #278) starts no timer at all.
 */
type Availability = Konventix.Resources.Data.PlanAvailabilityData;
type PlaceState = Konventix.Resources.Enums.PlaceState;

export const AVAILABILITY_POLL_MS = 15_000;

export function useAvailabilityPoll(
    initial: Record<string, PlaceState>,
    url: () => string,
    enabled: boolean,
): { availability: Ref<Record<string, PlaceState>> } {
    const availability = ref<Record<string, PlaceState>>({ ...initial });
    const http = useHttp<Record<string, never>, Availability>();
    let timer: ReturnType<typeof setInterval> | undefined;

    function refresh(): void {
        if (document.hidden || http.processing) {
            return;
        }

        http.get(url(), {
            onSuccess: (response) => {
                availability.value = response.places;
            },
        }).catch(() => undefined);
    }

    onMounted(() => {
        if (!enabled) {
            return;
        }

        timer = setInterval(refresh, AVAILABILITY_POLL_MS);
    });

    onBeforeUnmount(() => {
        if (timer) {
            clearInterval(timer);
        }

        http.cancel();
    });

    return { availability };
}

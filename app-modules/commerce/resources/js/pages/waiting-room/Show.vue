<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { status as waitingRoomStatus } from '@/routes/commerce/waiting-room';

/**
 * The holding page (ADR-0047 point 5, Task 13) `CommerceWaitingRoomGate`
 * sends a not-yet-admitted visitor to. Polls its own status endpoint every
 * five seconds and leaves the moment it reports `admitted` — no
 * Echo/WebSocket subscription: a thousand of those is exactly the load
 * ADR-0047 rejected ("Alternatives rejected: Echo for the holding page").
 * A `<noscript>` meta refresh keeps the page making progress even with
 * JavaScript disabled, at the cost of a full reload instead of a quiet
 * poll.
 */
type Props = {
    position: number;
    depth: number;
    admitPerInterval: number;
    intervalSeconds: number;
};

const props = defineProps<Props>();

const position = ref(props.position);
const depth = ref(props.depth);

/**
 * How many batches still stand between this ticket and the head of the
 * queue, times how long a batch takes to run, rounded up to whole
 * minutes — never zero, so "next in line" still reads as "about a
 * minute" rather than "about 0 minutes".
 */
const estimatedMinutes = computed(() => {
    const batches = Math.ceil(position.value / props.admitPerInterval);
    const totalSeconds = batches * props.intervalSeconds;

    return Math.max(1, Math.ceil(totalSeconds / 60));
});

/**
 * Copied from `resources/js/components/NotificationBell.vue` rather than
 * shared — this poll only ever reads, so it carries none of the bell's
 * XSRF handling for a write; a shared helper would exist only to be
 * half-used here.
 */
async function fetchJson(url: string): Promise<unknown> {
    const response = await fetch(url, {
        method: 'get',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(
            `Waiting room status request failed: ${response.status}`,
        );
    }

    return response.json();
}

let timer: ReturnType<typeof setInterval> | undefined;

async function poll(): Promise<void> {
    try {
        const data = (await fetchJson(
            waitingRoomStatus().url,
        )) as Konventix.Commerce.Data.WaitingRoomStatusData;

        if (data.admitted) {
            window.location.assign(data.redirectTo ?? '/shop');

            return;
        }

        if (data.position !== null) {
            position.value = data.position;
        }

        depth.value = data.depth;
    } catch {
        // A failed poll simply tries again in five seconds — the
        // `<noscript>` meta refresh below is the fallback for a browser
        // with no JavaScript at all, this catch is the fallback for one
        // request.
    }
}

onMounted(() => {
    timer = setInterval(poll, props.intervalSeconds * 1000);
});

onBeforeUnmount(() => {
    if (timer !== undefined) {
        clearInterval(timer);
    }
});
</script>

<template>
    <Head :title="$t('Waiting room')">
        <noscript>
            <meta http-equiv="refresh" content="10" />
        </noscript>
    </Head>

    <div
        class="mx-auto flex w-full max-w-xl flex-col items-center gap-8 p-6 text-center text-text lg:p-8"
    >
        <Heading
            as="h1"
            :title="$t('You are in the queue')"
            :description="
                $t(
                    'This page updates on its own — there is no need to refresh.',
                )
            "
        />

        <div
            class="flex w-full flex-col items-center gap-3 rounded-xl border border-border bg-surface-raised p-10"
        >
            <p class="text-lg text-text" aria-live="polite">
                {{ $t('Your position in the queue:') }}
                <span class="text-3xl font-semibold text-text tabular-nums">
                    {{ position }}
                </span>
            </p>

            <p class="text-sm text-text-muted">
                {{
                    $tChoice(
                        '{1} Estimated wait: about :count minute|[2,*] Estimated wait: about :count minutes',
                        estimatedMinutes,
                    )
                }}
            </p>
        </div>

        <p class="text-xs text-text-muted">
            {{
                $tChoice(
                    '{1} :count person is currently waiting.|[0,*] :count people are currently waiting.',
                    depth,
                )
            }}
        </p>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { checkedInKey, evaluateScan     } from './lib/evaluate';
import type {Decision, EventDay, LocalState, ReentryPolicy} from './lib/evaluate';
import { allDeltaEntries, bufferScan, getState, markSynced, pendingScans, putDeltaEntry, putState } from './lib/store';
import { EntranceApi, isStale } from './lib/sync';
import type { SigningKey } from './lib/token';

/**
 * The door screen (ADR-0033).
 *
 * Three things it deliberately does NOT do:
 * - show a holder's name: the delta is PII-free by design, so the device has
 *   no name to show and must not acquire one;
 * - take payment: ADR-0018 keeps door payment a Commerce point-of-sale
 *   operation that requires connectivity, on its own separately authorized
 *   screen;
 * - stop when the feed is stale: it degrades to signature-only and says so,
 *   because a closed door ruins an event.
 */

const deviceToken = ref((localStorage.getItem('entrance.device_token') ?? '').trim());
const eventId = ref(localStorage.getItem('entrance.event_id') ?? '');
const enrolling = computed(() => deviceToken.value === '' || eventId.value === '');

const keys = ref<SigningKey[]>([]);
const days = ref<EventDay[]>([]);
const reentryPolicy = ref<ReentryPolicy>('unlimited');
const revoked = ref<Set<string>>(new Set());
const checkedIn = ref<Set<string>>(new Set());
const cursor = ref(0);
const pollSeconds = ref(30);
const staleAfterSeconds = ref(900);
const lastSyncedAt = ref<Date | null>(null);
const now = ref(new Date());
const pendingCount = ref(0);
const manualToken = ref('');
const lastDecision = ref<Decision | null>(null);

const stale = computed(() => isStale(lastSyncedAt.value, staleAfterSeconds.value, now.value));

let timer: number | undefined;
let clock: number | undefined;

const api = computed(() => new EntranceApi(window.location.origin, deviceToken.value));

function localState(): LocalState {
    return {
        eventId: eventId.value,
        keys: keys.value,
        days: days.value,
        reentryPolicy: reentryPolicy.value,
        revoked: revoked.value,
        checkedIn: checkedIn.value,
    };
}

async function hydrate(): Promise<void> {
    keys.value = (await getState<SigningKey[]>('keys')) ?? [];
    days.value = (await getState<EventDay[]>('days')) ?? [];
    reentryPolicy.value = (await getState<ReentryPolicy>('reentry_policy')) ?? 'unlimited';
    cursor.value = (await getState<number>('cursor')) ?? 0;
    pollSeconds.value = (await getState<number>('poll_seconds')) ?? 30;
    staleAfterSeconds.value = (await getState<number>('stale_after_seconds')) ?? 900;

    const syncedAt = await getState<string>('last_synced_at');
    lastSyncedAt.value = syncedAt === undefined ? null : new Date(syncedAt);

    const entries = await allDeltaEntries();
    revoked.value = new Set(entries.filter((e) => e.kind === 'revoked').map((e) => e.admission_id));
    checkedIn.value = new Set(entries.filter((e) => e.kind === 'checked_in').map((e) => e.admission_id));

    pendingCount.value = (await pendingScans()).length;
}

/**
 * One sync pass. Every step is allowed to fail — an unreachable server is the
 * condition this client exists for, not an error state.
 */
async function sync(): Promise<void> {
    if (enrolling.value) {
        return;
    }

    try {
        const fetched = await api.value.signingKeys();
        keys.value = fetched;
        await putState('keys', fetched);
    } catch {
        // Keep the cached key set; it is what lets the door keep deciding.
    }

    try {
        const delta = await api.value.delta(cursor.value);

        for (const entry of delta.entries) {
            await putDeltaEntry(entry);

            if (entry.kind === 'revoked') {
                revoked.value.add(entry.admission_id);
            } else {
                checkedIn.value.add(entry.admission_id);
            }
        }

        cursor.value = delta.next;
        days.value = delta.days;
        reentryPolicy.value = delta.reentry_policy;
        pollSeconds.value = delta.poll_seconds;
        staleAfterSeconds.value = delta.stale_after_seconds;
        lastSyncedAt.value = new Date();

        await putState('cursor', delta.next);
        await putState('days', delta.days);
        await putState('reentry_policy', delta.reentry_policy);
        await putState('poll_seconds', delta.poll_seconds);
        await putState('stale_after_seconds', delta.stale_after_seconds);
        await putState('last_synced_at', lastSyncedAt.value.toISOString());
    } catch {
        return;
    }

    await flush();
}

/** Uploads buffered scans. The server is authoritative; this only reports. */
async function flush(): Promise<void> {
    const buffered = await pendingScans();

    if (buffered.length === 0) {
        pendingCount.value = 0;

        return;
    }

    try {
        const response = await api.value.submitScans(
            buffered.map((scan) => ({
                token: scan.token,
                client_scan_id: scan.client_scan_id,
                scanned_at: scan.scanned_at,
                direction: scan.direction,
            })),
        );

        await markSynced(response.results.map((result) => result.client_scan_id));
        pendingCount.value = (await pendingScans()).length;
    } catch {
        // Still buffered; the next pass tries again.
    }
}

/**
 * Decides locally, records locally, uploads later. The decision shown to door
 * staff is never delayed on the network.
 */
async function scan(token: string, direction: 'in' | 'out' = 'in'): Promise<void> {
    const at = new Date();
    const decision = evaluateScan(token, localState(), at);

    lastDecision.value = decision;

    if (decision.accepted) {
        // Remembered immediately so a second scan at THIS lane is caught even
        // before the delta has told the others.
        checkedIn.value.add(checkedInKey(decision.admissionId, reentryPolicy.value, decision.dayId));
    }

    await bufferScan({
        client_scan_id: crypto.randomUUID(),
        token,
        scanned_at: at.toISOString(),
        direction,
        local_result: decision.accepted ? 'accepted' : 'rejected',
        local_reason: decision.accepted ? null : decision.reason,
        synced: 0,
    });

    pendingCount.value = (await pendingScans()).length;

    void flush();
}

function submitManual(): void {
    const token = manualToken.value.trim();

    if (token !== '') {
        void scan(token);
        manualToken.value = '';
    }
}

function enrol(): void {
    localStorage.setItem('entrance.device_token', deviceToken.value.trim());
    localStorage.setItem('entrance.event_id', eventId.value.trim());
    void sync();
}

onMounted(async () => {
    await hydrate();
    void sync();

    timer = window.setInterval(() => void sync(), pollSeconds.value * 1000);
    clock = window.setInterval(() => (now.value = new Date()), 10_000);
});

onUnmounted(() => {
    window.clearInterval(timer);
    window.clearInterval(clock);
});
</script>

<template>
    <div class="door">
        <header class="bar">
            <span class="brand">Konventix Entrance</span>
            <span :class="['pill', stale ? 'pill-warn' : 'pill-ok']">
                {{ stale ? 'Signature-only (feed stale)' : 'Synced' }}
            </span>
            <span v-if="pendingCount > 0" class="pill pill-info">{{ pendingCount }} buffered</span>
        </header>

        <section v-if="enrolling" class="panel">
            <h1>Enrol this device</h1>
            <p>Paste the device token issued when this scanner was registered, and the event it serves.</p>
            <input v-model="deviceToken" class="field" placeholder="Device token" autocomplete="off" />
            <input v-model="eventId" class="field" placeholder="Event ID" autocomplete="off" />
            <button class="btn" @click="enrol">Enrol</button>
        </section>

        <section v-else class="panel">
            <div v-if="lastDecision" :class="['verdict', lastDecision.accepted ? 'ok' : 'no']">
                <strong>{{ lastDecision.accepted ? 'ADMIT' : 'REFUSE' }}</strong>
                <span v-if="!lastDecision.accepted" class="reason">{{ lastDecision.reason }}</span>
                <!-- No name is shown, and none can be: ADR-0033 keeps the
                     device PII-free, so the id is all the door has. -->
                <span v-if="lastDecision.admissionId" class="ref">{{ lastDecision.admissionId }}</span>
            </div>

            <input
                v-model="manualToken"
                class="field"
                placeholder="Scan or paste token"
                autocomplete="off"
                @keyup.enter="submitManual"
            />
            <button class="btn" @click="submitManual">Check</button>
        </section>
    </div>
</template>

<style scoped>
/* Semantic tokens only (ADR-0008, .ai/rules/resources.md). The token
   definitions are imported by `entrance-client.ts` so this bundle carries its
   own copy — a door tablet booting from cache with no uplink cannot rely on
   any stylesheet the rest of the app would have loaded for it. */
.door {
    min-height: 100vh;
    background: var(--surface);
    color: var(--text);
    font-family: var(--font-sans);
}
.bar {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border);
}
.brand {
    font-weight: 600;
    margin-right: auto;
}
.pill {
    font-size: 0.75rem;
    padding: 0.15rem 0.6rem;
    border-radius: 999px;
}
.pill-ok {
    background: var(--success);
    color: var(--success-contrast);
}
.pill-warn {
    background: var(--danger-surface);
    color: var(--danger);
}
.pill-info {
    background: var(--surface-muted);
    color: var(--text);
}
.panel {
    padding: 1.25rem;
    max-width: 32rem;
    margin: 0 auto;
}
.field {
    display: block;
    width: 100%;
    margin: 0.5rem 0;
    padding: 0.75rem;
    font-size: 1rem;
    border-radius: var(--radius);
    border: 1px solid var(--border);
    background: var(--surface-raised);
    color: inherit;
}
.btn {
    width: 100%;
    padding: 0.85rem;
    font-size: 1rem;
    font-weight: 600;
    border: 0;
    border-radius: var(--radius);
    background: var(--accent);
    color: var(--on-accent);
}
/* Deliberately oversized: this is read at arm's length, at a door, by someone
   holding a scanner in the other hand. */
.verdict {
    padding: 1.5rem;
    border-radius: var(--radius-lg);
    margin-bottom: 1rem;
    text-align: center;
}
.verdict strong {
    display: block;
    font-size: 2rem;
    letter-spacing: 0.05em;
}
.verdict.ok {
    background: var(--success);
    color: var(--success-contrast);
}
.verdict.no {
    background: var(--danger);
    color: var(--danger-contrast);
}
.reason {
    display: block;
    margin-top: 0.35rem;
    font-size: 0.9rem;
}
.ref {
    display: block;
    margin-top: 0.5rem;
    font-size: 0.7rem;
    opacity: 0.7;
}
</style>

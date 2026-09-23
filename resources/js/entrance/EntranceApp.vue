<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { checkedInKey, evaluateScan, REJECTION } from './lib/evaluate';
import type {
    Decision,
    EventDay,
    LocalState,
    ReentryPolicy,
    RejectionReason,
} from './lib/evaluate';
import { t } from './lib/i18n';
import {
    allConditions,
    allDeltaEntries,
    bufferScan,
    getState,
    markSynced,
    pendingScans,
    putConditions,
    putDeltaEntry,
    putState,
    resolveConditions,
} from './lib/store';
import type { ConditionOutcome, DoorCondition } from './lib/store';
import { EntranceApi, isStale } from './lib/sync';
import type { SigningKey } from './lib/token';

/**
 * The door screen (ADR-0033).
 *
 * Three things it deliberately does NOT do:
 * - show a holder's name: the delta is PII-free by design, so the device has
 *   no name to show and must not acquire one. Since ADR-0066 it does show
 *   what this admission owes at the door — "Document check — Student ID" —
 *   which is a label beside an opaque id and still says nothing about a
 *   person (spec §11);
 * - take payment: ADR-0018 keeps door payment a Commerce point-of-sale
 *   operation that requires connectivity, on its own separately authorized
 *   screen;
 * - stop when the feed is stale: it degrades to signature-only and says so,
 *   because a closed door ruins an event.
 */

const deviceToken = ref(
    (localStorage.getItem('entrance.device_token') ?? '').trim(),
);
const eventId = ref(localStorage.getItem('entrance.event_id') ?? '');
const enrolling = computed(
    () => deviceToken.value === '' || eventId.value === '',
);

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

/**
 * The document checks per admission, from the feed's `entry_conditions`
 * entries (ADR-0066 §6.4), and the one scan currently waiting for an answer.
 *
 * A scan with checks is buffered but held back from the upload until staff
 * answer or the next person is scanned: the outcome belongs to the scan, and
 * the server is idempotent per `client_scan_id`, so a scan uploaded before
 * the answer existed could never carry it afterwards. Nothing is ever lost —
 * the row is in IndexedDB from the moment it is scanned; only its upload
 * waits.
 */
const conditionsByAdmission = ref<Map<string, DoorCondition[]>>(new Map());
const pendingChecks = ref<DoorCondition[]>([]);
const pendingScanId = ref<string | null>(null);
const pendingOutcomes = ref<Record<string, 'confirmed' | 'refused'>>({});

/**
 * The refusal as a sentence staff can read out. The same words the console
 * uses for the same reason code (`CheckInRejectionReason::consoleSentence()`),
 * so the two surfaces share one translation.
 */
function reasonSentence(reason: RejectionReason): string {
    const sentences: Record<RejectionReason, string> = {
        [REJECTION.UnknownAdmission]: t('This ticket does not exist.'),
        [REJECTION.InvalidSignature]: t(
            'The ticket code could not be verified.',
        ),
        [REJECTION.TokenExpired]: t('The ticket code has expired.'),
        [REJECTION.NonceSuperseded]: t(
            'This ticket code has been replaced by a newer one.',
        ),
        [REJECTION.AdmissionNotValid]: t(
            'This ticket is not valid: it has been revoked or is outside its validity period.',
        ),
        [REJECTION.OutsideEventDay]: t(
            'There is no event day today, so nobody can be checked in right now.',
        ),
        [REJECTION.NotValidOnThisDay]: t(
            'This ticket is not valid on this event day.',
        ),
        [REJECTION.ReentryNotPermitted]: t(
            'This event does not permit re-entry, and this ticket has already been used to enter.',
        ),
        [REJECTION.WrongEvent]: t('This ticket belongs to a different event.'),
    };

    return sentences[reason] ?? reason;
}

const stale = computed(() =>
    isStale(lastSyncedAt.value, staleAfterSeconds.value, now.value),
);

let timer: number | undefined;
let clock: number | undefined;

const api = computed(
    () => new EntranceApi(window.location.origin, deviceToken.value),
);

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
    reentryPolicy.value =
        (await getState<ReentryPolicy>('reentry_policy')) ?? 'unlimited';
    cursor.value = (await getState<number>('cursor')) ?? 0;
    pollSeconds.value = (await getState<number>('poll_seconds')) ?? 30;
    staleAfterSeconds.value =
        (await getState<number>('stale_after_seconds')) ?? 900;

    const syncedAt = await getState<string>('last_synced_at');
    lastSyncedAt.value = syncedAt === undefined ? null : new Date(syncedAt);

    const entries = await allDeltaEntries();
    revoked.value = new Set(
        entries.filter((e) => e.kind === 'revoked').map((e) => e.admission_id),
    );
    checkedIn.value = new Set(
        entries
            .filter((e) => e.kind === 'checked_in')
            .map((e) => e.admission_id),
    );

    conditionsByAdmission.value = new Map(
        (await allConditions()).map((row) => [
            row.admission_id,
            row.conditions,
        ]),
    );

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
            // Each kind into its own place. `entry_conditions` deliberately
            // never reaches the delta store: that store is keyed by
            // admission id, so the two would overwrite each other.
            if (entry.kind === 'entry_conditions') {
                const conditions = entry.payload?.conditions ?? [];
                await putConditions({
                    admission_id: entry.admission_id,
                    conditions,
                });
                conditionsByAdmission.value.set(entry.admission_id, conditions);

                continue;
            }

            await putDeltaEntry(entry);

            if (entry.kind === 'revoked') {
                revoked.value.add(entry.admission_id);
            } else if (entry.kind === 'checked_in') {
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
                conditions_checked: scan.conditions_checked ?? [],
            })),
        );

        await markSynced(
            response.results.map((result) => result.client_scan_id),
        );
        pendingCount.value = (await pendingScans()).length;
    } catch {
        // Still buffered; the next pass tries again.
    }
}

/**
 * Decides locally, records locally, uploads later. The decision shown to door
 * staff is never delayed on the network.
 */
async function scan(
    token: string,
    direction: 'in' | 'out' = 'in',
): Promise<void> {
    // Whoever was on screen a moment ago is behind the person now at the
    // door: release their scan with whatever was answered, so a check nobody
    // got round to never holds a buffer back.
    await releasePendingScan();

    const at = new Date();
    const decision = evaluateScan(token, localState(), at);

    lastDecision.value = decision;

    if (decision.accepted) {
        // Remembered immediately so a second scan at THIS lane is caught even
        // before the delta has told the others.
        checkedIn.value.add(
            checkedInKey(
                decision.admissionId,
                reentryPolicy.value,
                decision.dayId,
            ),
        );
    }

    const checks = decision.accepted
        ? (conditionsByAdmission.value.get(decision.admissionId) ?? [])
        : [];
    const clientScanId = crypto.randomUUID();

    await bufferScan({
        client_scan_id: clientScanId,
        token,
        scanned_at: at.toISOString(),
        direction,
        local_result: decision.accepted ? 'accepted' : 'rejected',
        local_reason: decision.accepted ? null : decision.reason,
        conditions_checked: [],
        awaiting_conditions: checks.length > 0 ? 1 : 0,
        synced: 0,
    });

    pendingChecks.value = checks;
    pendingScanId.value = checks.length > 0 ? clientScanId : null;
    pendingOutcomes.value = {};

    pendingCount.value = (await pendingScans()).length;

    void flush();
}

/** Door staff answered one check. The last answer releases the scan. */
async function answerCheck(
    key: string,
    outcome: 'confirmed' | 'refused',
): Promise<void> {
    pendingOutcomes.value = { ...pendingOutcomes.value, [key]: outcome };

    if (
        pendingChecks.value.every(
            (check) => pendingOutcomes.value[check.key] !== undefined,
        )
    ) {
        await releasePendingScan();
    }
}

/**
 * Hands the answers to the buffered scan and lets it upload. Called when the
 * checks are all answered and again when the next person is scanned — a
 * check left unanswered uploads as "nothing was checked", which is the
 * honest record of a door that moved on.
 */
async function releasePendingScan(): Promise<void> {
    const clientScanId = pendingScanId.value;

    pendingScanId.value = null;
    pendingChecks.value = [];

    if (clientScanId === null) {
        return;
    }

    const outcomes: ConditionOutcome[] = Object.entries(
        pendingOutcomes.value,
    ).map(([key, outcome]) => ({ key, outcome }));

    pendingOutcomes.value = {};

    await resolveConditions(clientScanId, outcomes);

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
            <span class="brand">{{ $t('Konventix Entrance') }}</span>
            <span :class="['pill', stale ? 'pill-warn' : 'pill-ok']">
                {{ stale ? $t('Signature-only (feed stale)') : $t('Synced') }}
            </span>
            <span v-if="pendingCount > 0" class="pill pill-info">{{
                $t(':count buffered', { count: String(pendingCount) })
            }}</span>
        </header>

        <section v-if="enrolling" class="panel">
            <h1>{{ $t('Enrol this device') }}</h1>
            <p>
                {{
                    $t(
                        'Paste the device code issued when this scanner was registered, and the event it serves.',
                    )
                }}
            </p>
            <input
                v-model="deviceToken"
                class="field"
                :placeholder="$t('Device code')"
                autocomplete="off"
            />
            <input
                v-model="eventId"
                class="field"
                :placeholder="$t('Event ID')"
                autocomplete="off"
            />
            <button class="btn" @click="enrol">{{ $t('Enrol') }}</button>
        </section>

        <section v-else class="panel">
            <div
                v-if="lastDecision"
                :class="['verdict', lastDecision.accepted ? 'ok' : 'no']"
            >
                <strong>{{
                    lastDecision.accepted ? $t('ADMIT') : $t('REFUSE')
                }}</strong>
                <span v-if="!lastDecision.accepted" class="reason">{{
                    reasonSentence(lastDecision.reason)
                }}</span>
                <!-- No name is shown, and none can be: ADR-0033 keeps the
                     device PII-free, so the id is all the door has. -->
                <span v-if="lastDecision.admissionId" class="ref">{{
                    lastDecision.admissionId
                }}</span>
            </div>

            <!-- ADR-0066 §6.4: what this holder still has to show. Only a
                 `document_at_door` condition ever reaches a device, and a
                 refusal records a fact — it never turns the ADMIT above
                 into a REFUSE (T-8, ADR-0005). -->
            <div v-for="check in pendingChecks" :key="check.key" class="check">
                <strong>{{
                    $t('Document check — :label', { label: check.label })
                }}</strong>
                <span v-if="check.document_hint" class="hint">{{
                    check.document_hint
                }}</span>
                <div class="check-actions">
                    <button
                        class="btn btn-ok"
                        :aria-pressed="
                            pendingOutcomes[check.key] === 'confirmed'
                        "
                        @click="answerCheck(check.key, 'confirmed')"
                    >
                        {{ $t('Confirmed') }}
                    </button>
                    <button
                        class="btn btn-no"
                        :aria-pressed="pendingOutcomes[check.key] === 'refused'"
                        @click="answerCheck(check.key, 'refused')"
                    >
                        {{ $t('Refused') }}
                    </button>
                </div>
            </div>

            <input
                v-model="manualToken"
                class="field"
                :placeholder="$t('Scan or paste code')"
                autocomplete="off"
                @keyup.enter="submitManual"
            />
            <button class="btn" @click="submitManual">
                {{ $t('Check') }}
            </button>
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
/* The document check, read at the same arm's length as the verdict. */
.check {
    padding: 1rem;
    margin-bottom: 1rem;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface-raised);
}
.check strong {
    display: block;
    font-size: 1.1rem;
}
.check .hint {
    display: block;
    margin-top: 0.25rem;
    font-size: 0.9rem;
    color: var(--text-muted);
}
.check-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.75rem;
}
.check-actions .btn {
    width: auto;
    flex: 1;
}
.btn-ok {
    background: var(--success);
    color: var(--success-contrast);
}
.btn-no {
    background: var(--danger);
    color: var(--danger-contrast);
}
</style>

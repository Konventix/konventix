import { useHttp } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    update as updatePlan,
    validate as validatePlan,
} from '@/routes/admin/plans';
import type { PlanDocument } from './document';
import type { PlanStore } from './usePlanStore';

/**
 * Save and dry run through Inertia v3 `useHttp` (design §8.3). The three
 * server answers map onto three callbacks: 200 → `onSuccess`, a 422 →
 * `onError` with the body's `errors` (the controller mirrors the conflict
 * list there because that is all `useHttp` hands over for a 422), 409 and
 * anything else → `onHttpException`, whose `data` is still the raw body.
 *
 * `markSaved` receives the document that was sent, so an edit made while
 * the request was in flight keeps the pill on "Unsaved changes".
 */
type SaveResult = Konventix.Resources.Data.PlanSaveResultData;
type Validation = Konventix.Resources.Data.PlanValidationData;
type Conflict = Konventix.Resources.Data.PlanConflictData;
type Resolution = Konventix.Resources.Data.PlanConflictResolutionData;
type Warning = Konventix.Resources.Data.PlanWarningData;

type Payload = {
    expectedVersion: number;
    document: PlanDocument;
    resolutions: Resolution[];
};

export type SaveStatus = 'clean' | 'unsaved' | 'saving' | 'saved' | 'error';

const SAVED_PILL_MS = 2000;

function currentVersionOf(
    data: string | Record<string, unknown>,
): number | null {
    try {
        const parsed =
            typeof data === 'string'
                ? (JSON.parse(data) as Record<string, unknown>)
                : data;

        return typeof parsed.currentVersion === 'number'
            ? parsed.currentVersion
            : null;
    } catch {
        return null;
    }
}

export function usePlanSave(
    planId: string,
    initialVersion: number,
    store: PlanStore,
) {
    const version = ref(initialVersion);
    const phase = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
    const message = ref<string | null>(null);
    const warnings = ref<Warning[]>([]);
    const checkConflicts = ref<Conflict[]>([]);
    const saveConflicts = ref<Conflict[]>([]);
    const serverErrors = ref<Record<string, string | string[]>>({});
    let savedTimer: ReturnType<typeof setTimeout> | null = null;

    const payload = (): Payload => ({
        expectedVersion: version.value,
        document: store.document.value,
        resolutions: [],
    });
    const saveHttp = useHttp<Payload, SaveResult>(payload()).withAllErrors();
    const checkHttp = useHttp<Payload, Validation>(payload()).withAllErrors();

    const busy = computed(() => saveHttp.processing || checkHttp.processing);

    const status = computed<SaveStatus>(() => {
        if (phase.value === 'saving') {
            return 'saving';
        }

        if (phase.value === 'error') {
            return 'error';
        }

        if (store.isDirty.value) {
            return 'unsaved';
        }

        return phase.value === 'saved' ? 'saved' : 'clean';
    });

    function fail(text: string): void {
        phase.value = 'error';
        message.value = text;
    }

    function staleMessage(data: string | Record<string, unknown>): string {
        const current = currentVersionOf(data);

        return current === null
            ? 'Someone else saved this plan meanwhile. Reload the page and redo your changes.'
            : `Someone else saved version ${current} of this plan meanwhile. Reload the page and redo your changes.`;
    }

    function exceptionMessage(
        status: number,
        data: string | Record<string, unknown>,
    ): string {
        return status === 409
            ? staleMessage(data)
            : `The server answered HTTP ${status}.`;
    }

    async function save(resolutions: Resolution[] = []): Promise<void> {
        if (saveHttp.processing) {
            return;
        }

        const sent = store.document.value;
        saveHttp.expectedVersion = version.value;
        saveHttp.document = sent;
        saveHttp.resolutions = resolutions;
        phase.value = 'saving';
        message.value = null;
        serverErrors.value = {};

        await saveHttp
            .put(updatePlan(planId).url, {
                onSuccess: (result) => {
                    version.value = result.version;
                    warnings.value = result.warnings;
                    saveConflicts.value = [];
                    checkConflicts.value = [];
                    store.markSaved(sent);
                    phase.value = 'saved';

                    if (savedTimer) {
                        clearTimeout(savedTimer);
                    }

                    savedTimer = setTimeout(() => {
                        if (phase.value === 'saved') {
                            phase.value = 'idle';
                        }
                    }, SAVED_PILL_MS);
                },
                onError: (errors) => {
                    const list = (errors as Record<string, unknown>).conflicts;

                    if (Array.isArray(list)) {
                        saveConflicts.value = list as Conflict[];
                        phase.value = 'idle';

                        return;
                    }

                    serverErrors.value = errors as Record<
                        string,
                        string | string[]
                    >;
                    fail(
                        'The server refused the document — see the checks below.',
                    );
                },
                onHttpException: (response) => {
                    fail(exceptionMessage(response.status, response.data));
                },
                onNetworkError: () => {
                    fail(
                        'The save did not reach the server. Check the connection and try again.',
                    );
                },
            })
            .catch(() => undefined);
    }

    async function validate(): Promise<void> {
        checkHttp.expectedVersion = version.value;
        checkHttp.document = store.document.value;
        checkHttp.resolutions = [];
        serverErrors.value = {};

        await checkHttp
            .post(validatePlan(planId).url, {
                onSuccess: (result) => {
                    checkConflicts.value = result.conflicts;
                    warnings.value = result.warnings;

                    if (phase.value === 'error') {
                        phase.value = 'idle';
                        message.value = null;
                    }
                },
                onError: (errors) => {
                    serverErrors.value = errors as Record<
                        string,
                        string | string[]
                    >;
                },
                onHttpException: (response) => {
                    fail(exceptionMessage(response.status, response.data));
                },
                onNetworkError: () => {
                    fail('The check did not reach the server.');
                },
            })
            .catch(() => undefined);
    }

    function dismissConflicts(): void {
        saveConflicts.value = [];
    }

    return {
        version,
        status,
        message,
        busy,
        warnings,
        checkConflicts,
        saveConflicts,
        serverErrors,
        save,
        validate,
        dismissConflicts,
    };
}

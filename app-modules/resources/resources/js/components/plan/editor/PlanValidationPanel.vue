<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import type { InvariantIssue } from './invariants';

/**
 * One list for everything that stands between the document and a save:
 * client invariants (live), the server's validation errors from the last
 * attempt, the dry run's conflicts and the non-blocking warnings.
 */
type Conflict = Konventix.Resources.Data.PlanConflictData;
type Warning = Konventix.Resources.Data.PlanWarningData;

const props = defineProps<{
    issues: InvariantIssue[];
    conflicts: Conflict[];
    warnings: Warning[];
    serverErrors: Record<string, string | string[]>;
}>();

const emit = defineEmits<{ select: [elementId: string] }>();

const serverMessages = computed(() =>
    Object.entries(props.serverErrors)
        .filter(([key]) => key !== 'conflicts')
        .map(([key, value]) => ({
            key,
            text: Array.isArray(value) ? value.join(' ') : value,
        })),
);

// A lookup at call time, never a module constant: the words are catalog
// entries (ADR-0068).
function reasonText(reason: Conflict['reason']): string {
    const reasons: Record<Conflict['reason'], string> = {
        deleted: trans('would be deleted'),
        category_changed: trans('would change category'),
        blocked: trans('would be blocked'),
    };

    return reasons[reason];
}

const empty = computed(
    () =>
        props.issues.length === 0 &&
        props.conflicts.length === 0 &&
        props.warnings.length === 0 &&
        serverMessages.value.length === 0,
);
</script>

<template>
    <section
        class="max-h-40 overflow-y-auto rounded-md border border-border bg-surface p-3 text-sm"
        aria-labelledby="plan-checks"
    >
        <h2
            id="plan-checks"
            class="text-xs font-semibold text-text-muted uppercase"
        >
            {{ $t('Checks') }}
        </h2>
        <p v-if="empty" class="mt-1 text-text-muted">
            {{ $t('Nothing to report.') }}
        </p>
        <ul v-else class="mt-1 flex flex-col gap-1">
            <li v-for="issue in issues" :key="issue.key">
                <button
                    type="button"
                    class="text-left text-danger hover:underline"
                    @click="emit('select', issue.elementId)"
                >
                    {{ issue.message }}
                </button>
                <span class="ml-1 font-mono text-xs text-text-muted">{{
                    issue.key
                }}</span>
            </li>
            <li
                v-for="entry in serverMessages"
                :key="entry.key"
                class="text-danger"
            >
                {{ entry.text }}
                <span class="ml-1 font-mono text-xs text-text-muted">{{
                    entry.key
                }}</span>
            </li>
            <li v-for="conflict in conflicts" :key="conflict.placeId">
                <button
                    type="button"
                    class="text-left text-warning hover:underline"
                    @click="emit('select', conflict.placeId)"
                >
                    {{
                        $tChoice(
                            '{1} :place :reason while :count booking holds it.|[0,*] :place :reason while :count bookings hold it.',
                            conflict.bookings.length,
                            {
                                place:
                                    conflict.ref ??
                                    conflict.label ??
                                    conflict.placeId,
                                reason: reasonText(conflict.reason),
                            },
                        )
                    }}
                </button>
            </li>
            <!--
                A warning carries `placeIds: list<string>` and a `code`, never a
                single `placeId` (S1 `PlanWarningData`): an overlap is about two
                places at once. The row reveals the first of them, which is the
                one the operator needs to look at, and says how many more there
                are; a warning about the plan as a whole has an empty list.
            -->
            <li
                v-for="(warning, index) in warnings"
                :key="`${warning.code}-${warning.placeIds[0] ?? 'plan'}-${index}`"
            >
                <button
                    v-if="warning.placeIds.length > 0"
                    type="button"
                    class="text-left text-text-muted hover:underline"
                    @click="emit('select', warning.placeIds[0])"
                >
                    {{ warning.message }}
                    <span v-if="warning.placeIds.length > 1" class="text-xs">{{
                        $t('(+:count more)', {
                            count: String(warning.placeIds.length - 1),
                        })
                    }}</span>
                </button>
                <span v-else class="text-text-muted">{{
                    warning.message
                }}</span>
            </li>
        </ul>
    </section>
</template>

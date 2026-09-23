<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';
import { decide as decideCondition } from '@/routes/admin/registrations/entry-conditions';
import {
    assertionStateLabel,
    decisionSourceLabel,
    entryConditionReasonLabel,
    verificationLabel,
} from '../../lib/labels';

/**
 * The declarations one registration rests on, and what an operator may make
 * of each (ADR-0066 §9.1, D-REG-19/22).
 *
 * The three states the list has to be able to say out loud:
 *
 * - a **declaration** — what the registrant confirmed, in the wording they
 *   were shown, plus whatever was later decided about it.
 * - **not asserted** (`state === null`) — a condition added to the type
 *   after this registration existed. No row was written; the absence is the
 *   state. It is not an error, it blocks nothing, and *Waive* is the honest
 *   answer where the operator is satisfied.
 * - **no longer required** (`stillRequired === false`) — the condition left
 *   the type. The declaration stays: it is evidence, not configuration.
 *
 * Every control here is advisory. A refusal records a fact — it never
 * revokes an admission, never cancels the registration and never reaches
 * Commerce (T-8, ADR-0005). Operators who want a blocking check set
 * `approval: required` on the type.
 */
const props = defineProps<{
    registrationId: string;
    rows: Konventix.Registration.Data.EntryConditionAssertionData[];
    canVerify: boolean;
    reasonCodes: string[];
}>();

/** The condition id whose refusal reason is being picked, or null. */
const refusing = ref<string | null>(null);

const stateClasses: Record<string, string> = {
    asserted: 'bg-warning/15 text-warning',
    verified: 'bg-success/15 text-success',
    refused: 'bg-danger/15 text-danger',
    waived: 'bg-subtle text-text-muted',
};

function decide(
    conditionId: string,
    state: 'verified' | 'refused' | 'waived',
    reasonCode: string | null = null,
): void {
    router.post(
        decideCondition({
            registration: props.registrationId,
            entryCondition: conditionId,
        }).url,
        { state, reasonCode },
        { preserveScroll: true, onSuccess: () => (refusing.value = null) },
    );
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <p v-if="props.rows.length === 0" class="text-sm text-text-muted">
            {{ $t('This registration’s type demands no declaration.') }}
        </p>

        <div
            v-for="row in props.rows"
            :key="row.conditionId"
            class="flex flex-col gap-2 border-b border-border pb-4 last:border-0 last:pb-0"
        >
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium">{{ row.conditionName }}</span>
                <span
                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="
                        row.state === null
                            ? 'bg-subtle text-text-muted'
                            : stateClasses[row.state]
                    "
                >
                    {{
                        row.state === null
                            ? $t('not asserted')
                            : assertionStateLabel(row.state)
                    }}
                </span>
                <span
                    class="rounded-full bg-subtle px-2 py-0.5 text-xs text-text-muted"
                >
                    {{ verificationLabel(row.verification) }}
                </span>
            </div>

            <p class="text-sm text-text-muted">{{ row.assertionText }}</p>

            <p v-if="row.state === null" class="text-xs text-text-muted">
                {{
                    $t(
                        'Added to this type after this registration was made — nothing was written for it, and nothing is blocked.',
                    )
                }}
            </p>
            <p v-else-if="!row.stillRequired" class="text-xs text-text-muted">
                {{
                    $t(
                        'No longer required by this type. The declaration stays on the registration that made it.',
                    )
                }}
            </p>

            <p v-if="row.assertedAt" class="text-xs text-text-muted">
                {{ $t('Declared') }}
                <time :datetime="row.assertedAt">{{
                    formatDateTime(row.assertedAt)
                }}</time>
                <template v-if="row.decidedAt">
                    · {{ $t('decided') }}
                    <time :datetime="row.decidedAt">{{
                        formatDateTime(row.decidedAt)
                    }}</time>
                    <template v-if="row.decidedVia">
                        {{ decisionSourceLabel(row.decidedVia) }}
                    </template>
                    <template v-if="row.reasonCode">
                        ({{ entryConditionReasonLabel(row.reasonCode) }})
                    </template>
                </template>
            </p>

            <div
                v-if="props.canVerify"
                class="flex flex-wrap items-center gap-2"
            >
                <Button
                    v-if="row.state !== null"
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="decide(row.conditionId, 'verified')"
                >
                    {{ $t('Verify') }}
                </Button>
                <Button
                    v-if="row.state !== null"
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="refusing = row.conditionId"
                >
                    {{ $t('Refuse') }}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="decide(row.conditionId, 'waived')"
                >
                    {{ $t('Waive') }}
                </Button>

                <template v-if="refusing === row.conditionId">
                    <label
                        :for="`refuse-reason-${row.conditionId}`"
                        class="text-xs text-text-muted"
                    >
                        {{ $t('Reason') }}
                    </label>
                    <select
                        :id="`refuse-reason-${row.conditionId}`"
                        class="h-8 rounded-md border border-border bg-surface px-2 text-xs"
                        @change="
                            decide(
                                row.conditionId,
                                'refused',
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="" disabled selected>
                            {{ $t('Pick one') }}
                        </option>
                        <option
                            v-for="code in props.reasonCodes"
                            :key="code"
                            :value="code"
                        >
                            {{ entryConditionReasonLabel(code) }}
                        </option>
                    </select>
                </template>
            </div>
        </div>
    </div>
</template>

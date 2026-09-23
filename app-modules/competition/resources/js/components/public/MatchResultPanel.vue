<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { confirm, dispute, report } from '@/routes/competition/matches';
import { sideName } from '../bracket/bracketModel';

/**
 * Report, confirm, dispute — for a member of a side (design §8.2, §8.4).
 *
 * What is offered is the server's reading (`MatchViewerData`), and each
 * Action decides again; a refusal comes back as the `result` error under
 * the form that caused it.
 *
 * - **Report**: one score per side, keyed by slot position like every claim
 *   in §8. A second report replaces the side's own first one.
 * - **Confirm** takes no scores: the confirming side says "what they wrote
 *   is right" and cannot mistype it. It stays on offer during a dispute,
 *   because agreeing after all settles the match and closes the dispute on
 *   the server (fix wave B, item 12); the panel says so beside the button.
 * - **Dispute** needs a reason, and lands where the desk already looks —
 *   its open-dispute panel (§8.4).
 *
 * **No proof upload** (carry ruling, #322): while a proof is readable by
 * anyone holding its ULID the participant pages offer no upload control.
 * Where a competition requires one, the panel says so and that the desk
 * collects it — the report form is not offered, because a report without the
 * owed proof would only be refused.
 */
const props = defineProps<{
    competition: Konventix.Competition.Data.CompetitionDetailData;
    match: Konventix.Competition.Data.BracketMatchData;
    viewer: Konventix.Competition.Data.MatchViewerData;
}>();

const reportForm = useForm<{ scores: Record<number, string> }>({
    scores: Object.fromEntries(
        props.match.sides.map((side) => [
            side.position,
            String(props.viewer.ownReport?.[side.position] ?? ''),
        ]),
    ),
});

const disputeForm = useForm({ reason: '' });

useUnsavedChanges(() => reportForm.isDirty || disputeForm.isDirty);

const confirmError = ref<string | undefined>();

function url(route: typeof report): string {
    return route({
        competition: props.competition.id,
        match: props.match.id,
    }).url;
}

function submitReport(): void {
    reportForm
        .transform((data) => ({
            scores: Object.fromEntries(
                Object.entries(data.scores).map(([position, score]) => [
                    position,
                    score === '' ? null : Number(score),
                ]),
            ),
        }))
        .post(url(report), { preserveScroll: true });
}

function submitConfirm(): void {
    confirmError.value = undefined;

    router.post(
        url(confirm),
        {},
        {
            preserveScroll: true,
            onError: (errors) => (confirmError.value = errors.result),
        },
    );
}

function submitDispute(): void {
    disputeForm.post(url(dispute), {
        preserveScroll: true,
        onSuccess: () => disputeForm.reset(),
    });
}

function claim(scores: Record<number, number | null>): string {
    return props.match.sides
        .map((side) => `${sideName(side)} ${scores[side.position] ?? '–'}`)
        .join(' : ');
}
</script>

<template>
    <section
        class="flex flex-col gap-4 rounded-xl border border-border bg-surface-raised p-4"
        aria-labelledby="result-heading"
    >
        <h2 id="result-heading" class="text-lg font-medium text-text">
            {{ $t('Your result') }}
        </h2>

        <p
            v-if="props.competition.resultSubmissionMode === 'desk_only'"
            class="text-sm text-text-muted"
        >
            {{
                $t(
                    'In this tournament, the organizers enter the results. Come to the tournament desk after the match.',
                )
            }}
        </p>

        <p
            v-else-if="props.competition.proofRequired"
            class="text-sm text-text"
        >
            {{
                $t(
                    'This tournament requires proof (such as a screenshot) with every result. The organizers take the result and the proof at the tournament desk.',
                )
            }}
        </p>

        <p
            v-if="props.match.disputed"
            role="status"
            class="text-sm text-danger"
        >
            {{
                $t(
                    'This result is disputed. The organizers decide and explain their decision in the history.',
                )
            }}
        </p>

        <p v-if="props.viewer.opposingReport" class="text-sm text-text">
            {{ $t('Your opponent reports:') }}
            <strong>{{ claim(props.viewer.opposingReport) }}</strong>
        </p>

        <p
            v-if="props.viewer.ownReport && !props.viewer.opposingReport"
            class="text-sm text-text-muted"
        >
            {{
                $t(
                    'You reported :score. The result counts once your opponent confirms it.',
                    { score: claim(props.viewer.ownReport) },
                )
            }}
        </p>

        <div v-if="props.viewer.canConfirm" class="flex flex-col gap-1">
            <p v-if="props.match.disputed" class="text-sm text-text">
                {{
                    $t(
                        'Do you agree with your opponent’s result after all? If you confirm it, it counts and the dispute is settled.',
                    )
                }}
            </p>
            <div>
                <Button @click="submitConfirm">{{
                    $t('Confirm result')
                }}</Button>
            </div>
            <InputError :message="confirmError" role="alert" />
        </div>

        <form
            v-if="props.viewer.canReport"
            class="flex flex-col gap-3"
            @submit.prevent="submitReport"
        >
            <h3 class="text-sm font-medium text-text">
                {{
                    props.viewer.opposingReport
                        ? $t('Report a different result')
                        : $t('Report result')
                }}
            </h3>
            <div class="flex flex-wrap gap-3">
                <div
                    v-for="side in props.match.sides"
                    :key="side.position"
                    class="flex flex-col gap-1"
                >
                    <Label :for="`score-${side.position}`">{{
                        sideName(side)
                    }}</Label>
                    <Input
                        :id="`score-${side.position}`"
                        v-model="reportForm.scores[side.position]"
                        type="number"
                        min="0"
                        class="w-24"
                        :aria-invalid="
                            reportForm.errors.scores ? 'true' : undefined
                        "
                        :aria-describedby="
                            reportForm.errors.scores
                                ? 'report-error'
                                : undefined
                        "
                    />
                </div>
            </div>
            <InputError id="report-error" :message="reportForm.errors.scores" />
            <div class="flex flex-wrap items-center gap-3">
                <Button type="submit" :disabled="reportForm.processing">
                    {{ $t('Report') }}
                </Button>
                <span
                    v-if="reportForm.isDirty"
                    role="status"
                    class="text-sm text-warning"
                    >{{ $t('Unsaved changes') }}</span
                >
            </div>
        </form>

        <form
            v-if="props.viewer.canDispute"
            class="flex flex-col gap-2"
            @submit.prevent="submitDispute"
        >
            <Label for="dispute-reason">{{ $t('Dispute result') }}</Label>
            <Textarea
                id="dispute-reason"
                v-model="disputeForm.reason"
                maxlength="500"
                rows="3"
                :aria-invalid="disputeForm.errors.reason ? 'true' : undefined"
                aria-describedby="dispute-hint dispute-error"
            />
            <p id="dispute-hint" class="text-sm text-text-muted">
                {{
                    $t(
                        'Say what is wrong. The organizers see both reports and decide.',
                    )
                }}
            </p>
            <InputError
                id="dispute-error"
                :message="disputeForm.errors.reason"
            />
            <div>
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="
                        disputeForm.processing || disputeForm.reason === ''
                    "
                >
                    {{ $t('Dispute result') }}
                </Button>
            </div>
        </form>
    </section>
</template>

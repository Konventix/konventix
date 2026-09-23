<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    correct as correctMatch,
    record as recordMatch,
    resolve as resolveMatch,
} from '@/routes/admin/competition-matches';
import { matchLabel } from './matchLabel';

/**
 * The desk's result dialog (design §8, §9): record, correct or resolve — one
 * dialog because the three ask the same thing, a score per side and who won.
 *
 * - **record** (`RecordResults`) finishes an open match and advances the
 *   bracket;
 * - **correct** (`CorrectResults`) rewrites a finished one. If that changes
 *   who went through, every downstream result it takes away needs a reason,
 *   which the server asks for on `note`;
 * - **resolve** decides a dispute and always needs a note, because both
 *   sides are shown why (§8.4).
 *
 * The winner follows the highest score until the desk picks one by hand —
 * the desk may award a match on something the score line does not show —
 * and "Draw" sends none.
 *
 * Record and resolve key the scores by slot position; a correction keys them
 * by entrant, which is what `CorrectMatchResult` takes (`DeskScoreLineData`).
 * Nothing here uploads a proof (§8.7, #322).
 */
export type ResultMode = 'record' | 'correct' | 'resolve';

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    match: Konventix.Competition.Data.DeskMatchData | null;
    mode: ResultMode;
}>();

const TITLES: Record<ResultMode, string> = {
    record: 'Record result',
    correct: 'Correct result',
    resolve: 'Resolve dispute',
};

const sides = computed(() =>
    (props.match?.sides ?? []).filter((side) => side.entrantId !== null),
);

const form = useForm({
    scores: {} as Record<string, number | null>,
    winnerEntrantId: null as string | null,
    note: '',
});

const winnerTouched = ref(false);

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    winnerTouched.value = false;
    form.scores = Object.fromEntries(
        sides.value.map((side) => [String(side.position), side.score]),
    );
    form.winnerEntrantId =
        sides.value.find((side) => side.winner)?.entrantId ?? null;
    form.note = '';
});

/** The single highest score's side, or none on a tie or an empty line. */
const leader = computed((): string | null => {
    let best: number | null = null;
    let leaders: string[] = [];

    for (const side of sides.value) {
        const score = form.scores[String(side.position)];

        if (score === null || score === undefined || side.entrantId === null) {
            continue;
        }

        if (best === null || score > best) {
            best = score;
            leaders = [side.entrantId];
        } else if (score === best) {
            leaders.push(side.entrantId);
        }
    }

    return leaders.length === 1 ? leaders[0] : null;
});

watch(leader, (entrantId) => {
    if (!winnerTouched.value) {
        form.winnerEntrantId = entrantId;
    }
});

const endpoint = computed(() => {
    const id = props.match?.id ?? '';

    return {
        record: recordMatch(id),
        correct: correctMatch(id),
        resolve: resolveMatch(id),
    }[props.mode].url;
});

const noteError = computed(
    () =>
        form.errors.note ??
        (form.errors as Record<string, string | undefined>).resolutionNote,
);

/** A refused action from the server's side — see `AnswersTheDesk`. */
const refusal = computed(
    () => (form.errors as Record<string, string | undefined>).desk,
);

function submit(): void {
    form.transform((data) => ({
        winnerEntrantId: data.winnerEntrantId,
        note: data.note === '' ? null : data.note,
        scores:
            props.mode === 'correct'
                ? Object.fromEntries(
                      sides.value.map((side) => [
                          side.entrantId,
                          data.scores[String(side.position)] ?? null,
                      ]),
                  )
                : data.scores,
    })).post(endpoint.value, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ TITLES[props.mode] }}</DialogTitle>
                <DialogDescription v-if="props.match">
                    {{ matchLabel(props.match) }}
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div
                    v-for="side in sides"
                    :key="side.position"
                    class="grid gap-2"
                >
                    <Label :for="`desk-score-${side.position}`">
                        {{ side.teamName }}
                    </Label>
                    <Input
                        :id="`desk-score-${side.position}`"
                        v-model.number="form.scores[String(side.position)]"
                        type="number"
                        min="0"
                        inputmode="numeric"
                    />
                </div>
                <InputError :message="form.errors.scores" />

                <div class="grid gap-2">
                    <Label for="desk-winner">Winner</Label>
                    <select
                        id="desk-winner"
                        v-model="form.winnerEntrantId"
                        class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                        @change="winnerTouched = true"
                    >
                        <option :value="null">Draw — nobody advances</option>
                        <option
                            v-for="side in sides"
                            :key="side.position"
                            :value="side.entrantId"
                        >
                            {{ side.teamName }}
                        </option>
                    </select>
                    <InputError :message="form.errors.winnerEntrantId" />
                </div>

                <div v-if="props.mode !== 'record'" class="grid gap-2">
                    <Label for="desk-note">
                        {{
                            props.mode === 'resolve'
                                ? 'Why (both teams see this)'
                                : 'Why (needed if a later result is voided)'
                        }}
                    </Label>
                    <Textarea
                        id="desk-note"
                        v-model="form.note"
                        :required="props.mode === 'resolve'"
                        maxlength="500"
                        :aria-invalid="noteError ? 'true' : undefined"
                        aria-describedby="desk-note-error"
                    />
                    <InputError id="desk-note-error" :message="noteError" />
                </div>

                <InputError :message="refusal" />

                <div class="flex justify-end">
                    <Button type="submit" :disabled="form.processing">
                        {{ TITLES[props.mode] }}
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>

<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { store } from '@/routes/competition/score-entries';

/**
 * A leaderboard score for the viewer's own team (design §8.3). It counts
 * once the desk has verified it; a new number for the same heat replaces an
 * unverified one. The entrant is never sent — the server takes the viewer's
 * own from their roster.
 *
 * No proof upload: until Platform can say who may read a proof (#322) the
 * participant pages offer none, and where one is required the competition
 * page says the desk collects it instead of showing this form.
 */
const props = defineProps<{
    competition: Konventix.Competition.Data.CompetitionDetailData;
}>();

const form = useForm<{ value: string; heat: string; note: string }>({
    value: '',
    heat: props.competition.heats === null ? '' : '1',
    note: '',
});

useUnsavedChanges(() => form.isDirty);

function submit(): void {
    form.transform((data) => ({
        value: data.value.replace(',', '.'),
        heat: data.heat === '' ? null : Number(data.heat),
        note: data.note === '' ? null : data.note,
    })).post(store(props.competition.id).url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <form
        class="flex flex-col gap-3 rounded-xl border border-border bg-surface-raised p-4"
        aria-labelledby="score-heading"
        @submit.prevent="submit"
    >
        <h2 id="score-heading" class="text-lg font-medium text-text">
            Ergebnis einreichen
        </h2>

        <div class="flex flex-wrap gap-3">
            <div class="flex flex-col gap-1">
                <Label for="score-value"
                    >Wertung<template v-if="props.competition.unit">
                        ({{ props.competition.unit }})</template
                    ></Label
                >
                <Input
                    id="score-value"
                    v-model="form.value"
                    inputmode="decimal"
                    class="w-40"
                    :aria-invalid="form.errors.value ? 'true' : undefined"
                    :aria-describedby="
                        form.errors.value ? 'score-value-error' : undefined
                    "
                />
                <InputError
                    id="score-value-error"
                    :message="form.errors.value"
                />
            </div>

            <div
                v-if="props.competition.heats !== null"
                class="flex flex-col gap-1"
            >
                <Label for="score-heat">Lauf</Label>
                <select
                    id="score-heat"
                    v-model="form.heat"
                    class="rounded-md border border-input bg-surface px-3 py-2 text-sm text-text"
                    :aria-invalid="form.errors.heat ? 'true' : undefined"
                    :aria-describedby="
                        form.errors.heat ? 'score-heat-error' : undefined
                    "
                >
                    <option
                        v-for="heat in props.competition.heats"
                        :key="heat"
                        :value="String(heat)"
                    >
                        Lauf {{ heat }}
                    </option>
                </select>
                <InputError id="score-heat-error" :message="form.errors.heat" />
            </div>
        </div>

        <div class="flex flex-col gap-1">
            <Label for="score-note">Anmerkung (optional)</Label>
            <Input id="score-note" v-model="form.note" maxlength="255" />
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Button
                type="submit"
                :disabled="form.processing || form.value === ''"
            >
                Einreichen
            </Button>
            <span v-if="form.isDirty" role="status" class="text-sm text-warning"
                >Nicht gespeicherte Änderungen</span
            >
        </div>
    </form>
</template>

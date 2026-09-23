<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { formatDateTime } from '@/lib/datetime';
import {
    reject as rejectEntry,
    verify as verifyEntry,
} from '@/routes/admin/competition-score-entries';
import { scoreEntries } from '@/routes/admin/competition-stages';

/**
 * A leaderboard at the desk (design §7.2, §8.3, §9): the verification queue,
 * and the desk's own score form.
 *
 * A submitted score does not count until the desk verifies it; rejecting
 * one needs a reason, because the participant is shown it and resubmits. A
 * score the desk types itself is verified on creation — the path a
 * `desk_only` leaderboard runs on entirely.
 *
 * A proof a participant attached opens in a new tab; the desk form has no
 * upload (§8.7, #322).
 */
const props = defineProps<{
    stage: Konventix.Competition.Data.DeskStageData;
    queue: Konventix.Competition.Data.DeskScoreEntryData[];
    field: Konventix.Competition.Data.DeskEntrantData[];
}>();

const rejecting = ref<string | null>(null);
const rejectionNote = ref('');
const rejectionError = ref<string>();

function verify(entryId: string): void {
    router.post(verifyEntry(entryId).url, {}, { preserveScroll: true });
}

function reject(entryId: string): void {
    router.post(
        rejectEntry(entryId).url,
        { rejectionNote: rejectionNote.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                rejecting.value = null;
                rejectionNote.value = '';
                rejectionError.value = undefined;
            },
            onError: (errors) => (rejectionError.value = errors.rejectionNote),
        },
    );
}

const form = useForm({
    entrantId: props.field[0]?.id ?? '',
    heat: (props.stage.heats === null ? null : 1) as number | null,
    value: null as number | null,
    note: '',
});

useUnsavedChanges(() => form.isDirty);

function enter(): void {
    form.transform((data) => ({
        ...data,
        note: data.note === '' ? null : data.note,
    })).post(scoreEntries(props.stage.id).url, {
        preserveScroll: true,
        onSuccess: () => form.reset('value', 'note'),
    });
}
</script>

<template>
    <section class="flex flex-col gap-4" aria-labelledby="desk-queue">
        <h2 id="desk-queue" class="text-lg font-medium text-text">
            {{ $t('Verification queue') }}
            <span class="text-text-muted">({{ props.queue.length }})</span>
        </h2>

        <p v-if="props.queue.length === 0" class="text-sm text-text-muted">
            {{ $t('Nothing waits for verification.') }}
        </p>

        <ul v-else class="flex flex-col divide-y divide-border">
            <li
                v-for="entry in props.queue"
                :key="entry.id"
                class="flex flex-col gap-2 py-2"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-sm text-text">
                        <strong>{{ entry.teamName }}</strong>
                        <template v-if="entry.heat !== null">
                            ·
                            {{
                                $t('heat :heat', { heat: String(entry.heat) })
                            }}</template
                        >
                        · {{ entry.value }} {{ props.stage.unit }}
                        <span class="text-text-muted">
                            · {{ formatDateTime(entry.submittedAt) }}</span
                        >
                        <template v-if="entry.note">
                            — {{ entry.note }}</template
                        >
                    </span>
                    <div class="flex flex-wrap items-center gap-2">
                        <a
                            v-if="entry.proofUrl"
                            :href="entry.proofUrl"
                            target="_blank"
                            rel="noopener"
                            class="text-sm underline underline-offset-4 hover:text-accent"
                            >{{ $t('View proof') }}</a
                        >
                        <Button size="sm" @click="verify(entry.id)">
                            {{ $t('Accept') }}
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="rejecting = entry.id"
                        >
                            {{ $t('Reject') }}
                        </Button>
                    </div>
                </div>

                <form
                    v-if="rejecting === entry.id"
                    class="flex flex-wrap items-end gap-2"
                    @submit.prevent="reject(entry.id)"
                >
                    <div class="grid flex-1 gap-1">
                        <Label :for="`desk-reject-${entry.id}`">
                            {{ $t('Why (the participant sees this)') }}
                        </Label>
                        <Input
                            :id="`desk-reject-${entry.id}`"
                            v-model="rejectionNote"
                            maxlength="255"
                            required
                        />
                        <InputError :message="rejectionError" />
                    </div>
                    <Button type="submit" size="sm" variant="destructive">
                        {{ $t('Reject score') }}
                    </Button>
                </form>
            </li>
        </ul>

        <form
            class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-surface-raised p-4"
            @submit.prevent="enter"
        >
            <h3 class="w-full font-medium text-text">
                {{ $t('Enter a score') }}
            </h3>

            <div class="grid gap-1">
                <Label for="desk-score-entrant">{{ $t('Team') }}</Label>
                <select
                    id="desk-score-entrant"
                    v-model="form.entrantId"
                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                >
                    <option
                        v-for="entrant in props.field"
                        :key="entrant.id"
                        :value="entrant.id"
                    >
                        {{ entrant.teamName }}
                    </option>
                </select>
            </div>

            <div v-if="props.stage.heats !== null" class="grid gap-1">
                <Label for="desk-score-heat">{{ $t('Heat') }}</Label>
                <select
                    id="desk-score-heat"
                    v-model.number="form.heat"
                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                >
                    <option
                        v-for="heat in props.stage.heats"
                        :key="heat"
                        :value="heat"
                    >
                        {{ heat }}
                    </option>
                </select>
            </div>

            <div class="grid gap-1">
                <Label for="desk-score-value">{{
                    $t('Score (:unit)', { unit: props.stage.unit ?? '' })
                }}</Label>
                <Input
                    id="desk-score-value"
                    v-model.number="form.value"
                    type="number"
                    step="any"
                    required
                />
            </div>

            <div class="grid flex-1 gap-1">
                <Label for="desk-score-note">{{ $t('Note') }}</Label>
                <Input
                    id="desk-score-note"
                    v-model="form.note"
                    maxlength="255"
                />
            </div>

            <Button type="submit" size="sm" :disabled="form.processing">
                {{ $t('Enter score') }}
            </Button>
            <span
                v-if="form.isDirty"
                role="status"
                class="text-sm text-warning"
            >
                {{ $t('Unsaved changes') }}
            </span>

            <div class="w-full">
                <InputError :message="form.errors.value" />
                <InputError :message="form.errors.heat" />
                <InputError :message="form.errors.entrantId" />
            </div>
        </form>
    </section>
</template>

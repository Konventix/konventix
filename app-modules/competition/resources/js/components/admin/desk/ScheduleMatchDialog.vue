<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import DateTimeField from '@/components/datetime/DateTimeField.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { toInstant, toLocalDateTimeInput } from '@/lib/datetime';
import { schedule as scheduleMatch } from '@/routes/admin/competition-matches';
import { matchLabel } from './matchLabel';

/**
 * One match's time (D-CMP-21) — the exception to the per-round "schedule all
 * at". Typed in the reader's own zone and sent as an instant
 * (.ai/rules/js.md); the server writes `scheduled` or `rescheduled` into the
 * match's history and tells GameOps.
 */
const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    match: Konventix.Competition.Data.DeskMatchData | null;
    startsAt: string | null;
}>();

const form = useForm({ scheduledAt: '' });

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.scheduledAt = toLocalDateTimeInput(
            props.match?.scheduledAt ?? props.startsAt,
        );
    }
});

/** A refused action from the server's side — see `AnswersTheDesk`. */
const refusal = computed(
    () => (form.errors as Record<string, string | undefined>).desk,
);

function submit(): void {
    if (props.match === null) {
        return;
    }

    form.transform((data) => ({
        scheduledAt: toInstant(data.scheduledAt),
    })).put(scheduleMatch(props.match.id).url, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>
                    {{
                        $t('Schedule :match', {
                            match: props.match ? matchLabel(props.match) : '',
                        })
                    }}
                </DialogTitle>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <DateTimeField
                    id="desk-match-scheduled-at"
                    v-model="form.scheduledAt"
                    :label="$t('Starts at')"
                    :error="form.errors.scheduledAt"
                    required
                />
                <InputError :message="refusal" />

                <div class="flex justify-end">
                    <Button type="submit" :disabled="form.processing">
                        {{ $t('Save time') }}
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>

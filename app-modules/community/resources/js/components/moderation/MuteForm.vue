<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';
import ModerationController from '@/actions/Konventix/Community/Http/Controllers/ModerationController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';

/**
 * The desk's mute form (D-COM-08): a duration and a reason the muted person
 * is shown — "You cannot post for 10 minutes: Spam". Console copy; the
 * reason is the one sentence here a participant reads, as typed.
 *
 * The server validates through `MuteData`, so a reason past its cap comes
 * back as a field error; `maxLength` is that same cap, handed over by the
 * page. A stateful form, so it carries the unsaved-changes guard
 * (ADR-0038).
 */
const props = defineProps<{
    eventId: string;
    target: { userId: string; name: string };
    maxLength: number;
}>();

const emit = defineEmits<{ close: [] }>();

const form = useForm({
    reason: '',
    minutes: 10 as number | null,
});

const durations = computed<Array<{ label: string; minutes: number | null }>>(
    () => [
        ...[5, 10, 30].map((minutes) => ({
            label: transChoice(
                '{1} :count minute|[2,*] :count minutes',
                minutes,
            ),
            minutes,
        })),
        {
            label: transChoice('{1} :count hour|[2,*] :count hours', 1),
            minutes: 60,
        },
        { label: trans('Rest of the event'), minutes: null },
    ],
);

useUnsavedChanges(() => form.isDirty);

function submit(): void {
    form.post(
        ModerationController.mute([props.eventId, props.target.userId]).url,
        { preserveScroll: true, onSuccess: () => emit('close') },
    );
}
</script>

<template>
    <Card class="border-border bg-surface">
        <CardHeader>
            <CardTitle>{{ $t('Mute :name', { name: target.name }) }}</CardTitle>
        </CardHeader>
        <CardContent>
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="mute-minutes">{{ $t('Duration') }}</Label>
                    <select
                        id="mute-minutes"
                        v-model="form.minutes"
                        class="h-9 rounded-md border border-border bg-surface px-3 text-sm text-text"
                    >
                        <option
                            v-for="duration in durations"
                            :key="String(duration.minutes)"
                            :value="duration.minutes"
                        >
                            {{ duration.label }}
                        </option>
                    </select>
                    <InputError :message="form.errors.minutes" />
                </div>
                <div class="grid gap-2">
                    <Label for="mute-reason">
                        {{ $t('Reason — shown to the participant') }}
                    </Label>
                    <input
                        id="mute-reason"
                        v-model="form.reason"
                        type="text"
                        :maxlength="maxLength"
                        required
                        class="h-9 rounded-md border border-border bg-surface px-3 text-sm text-text"
                    />
                    <InputError :message="form.errors.reason" />
                </div>
                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing">
                        {{ $t('Mute') }}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="emit('close')"
                    >
                        {{ $t('Cancel') }}
                    </Button>
                    <span
                        v-if="form.isDirty"
                        role="status"
                        class="text-sm text-warning"
                    >
                        {{ $t('Unsaved changes') }}
                    </span>
                </div>
            </form>
        </CardContent>
    </Card>
</template>

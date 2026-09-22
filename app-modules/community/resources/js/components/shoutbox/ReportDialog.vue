<script setup lang="ts">
import { ref, useId, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { shoutboxRequest } from './useShoutboxWall';
import type { WallLine } from './wall';

/**
 * Reporting one line (spec §4.4, D-COM-01): one optional note, no category
 * list — the desk reads the line itself. A second report of the same line
 * by the same person is the same report, so the server answers it the same
 * way and this says the same thing.
 */
const props = defineProps<{
    line: WallLine | null;
    reportUrl: (messageId: string) => string;
    noteMaxLength: number;
}>();

const emit = defineEmits<{ close: [] }>();

const note = ref('');
const error = ref<string | null>(null);
const sending = ref(false);
const noteId = useId();
const errorId = useId();

watch(
    () => props.line,
    () => {
        note.value = '';
        error.value = null;
    },
);

async function submit(): Promise<void> {
    if (props.line === null || sending.value) {
        return;
    }

    sending.value = true;

    try {
        // A browser fetch of a fixed same-origin Wayfinder route; see
        // `useShoutboxWall.ts`.
        // gitlab-advanced-sast-exclude
        const response = await fetch(
            props.reportUrl(props.line.id),
            shoutboxRequest('post', {
                note: note.value === '' ? null : note.value,
            }),
        );

        if (response.ok) {
            toast.success('Danke. Die Orga schaut sich die Nachricht an.');
            emit('close');

            return;
        }

        const body = (await response.json().catch(() => null)) as {
            message?: string;
            errors?: Record<string, string[] | undefined>;
        } | null;

        error.value =
            body?.errors?.note?.[0] ??
            body?.message ??
            'Die Meldung konnte nicht gesendet werden.';
    } catch {
        error.value = 'Die Meldung konnte nicht gesendet werden.';
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <Dialog
        :open="line !== null"
        @update:open="(open) => !open && emit('close')"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Nachricht melden</DialogTitle>
                <DialogDescription>
                    Die Orga sieht die Nachricht und deinen Hinweis. Wer sie
                    geschrieben hat, erfährt nicht, dass du sie gemeldet hast.
                </DialogDescription>
            </DialogHeader>
            <form class="flex flex-col gap-2" @submit.prevent="submit">
                <blockquote
                    v-if="line"
                    class="border-l-2 border-border pl-3 text-sm text-text-muted"
                >
                    {{ line.body }}
                </blockquote>
                <label :for="noteId" class="text-sm font-medium text-text">
                    Hinweis (optional)
                </label>
                <textarea
                    :id="noteId"
                    v-model="note"
                    rows="3"
                    :maxlength="noteMaxLength"
                    :aria-invalid="error !== null ? 'true' : undefined"
                    :aria-describedby="error !== null ? errorId : undefined"
                    class="w-full resize-none rounded-md border border-border bg-surface px-3 py-2 text-sm text-text focus-visible:ring-2 focus-visible:ring-accent focus-visible:outline-none"
                />
                <p v-if="error" :id="errorId" class="text-sm text-danger">
                    {{ error }}
                </p>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="emit('close')"
                    >
                        Abbrechen
                    </Button>
                    <Button type="submit" :disabled="sending">Melden</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

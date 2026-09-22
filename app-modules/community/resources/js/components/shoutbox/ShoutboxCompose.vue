<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { Button } from '@/components/ui/button';

/**
 * The compose box (design §8), shared by the hub card and the room page.
 * German, because participants read it (ADR-0001).
 *
 * The text leaves the field the moment it is sent — the wall shows it as
 * pending — and comes back if the post did not land, so a refusal never
 * costs somebody what they typed.
 *
 * What stands between the viewer and posting is always the server's own
 * sentence: `notice` for the room (locked, closed), `refusal` for the
 * viewer (a mute, the rate limit, a line that was too long). A timed hold
 * shows its countdown as a `timer` without a live region, so a screen
 * reader is told the sentence once rather than every second
 * (`.ai/rules/js.md`).
 */
const props = defineProps<{
    canWrite: boolean;
    notice: string | null;
    refusal: string | null;
    remaining: number | null;
    maxLength: number;
    send: (text: string) => Promise<boolean>;
}>();

const draft = ref('');
const sending = ref(false);
const fieldId = useId();
const errorId = useId();

const tooLong = computed(() => draft.value.trim().length > props.maxLength);

async function submit(): Promise<void> {
    const text = draft.value;

    if (
        text.trim() === '' ||
        tooLong.value ||
        !props.canWrite ||
        sending.value
    ) {
        return;
    }

    sending.value = true;
    draft.value = '';

    const landed = await props.send(text);

    if (!landed && draft.value === '') {
        draft.value = text;
    }

    sending.value = false;
}

function onEnter(event: KeyboardEvent): void {
    if (!event.shiftKey) {
        event.preventDefault();
        void submit();
    }
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <p v-if="notice" class="text-sm text-warning" role="status">
            {{ notice }}
        </p>
        <form v-else class="flex flex-col gap-2" @submit.prevent="submit">
            <label :for="fieldId" class="sr-only">Nachricht</label>
            <textarea
                :id="fieldId"
                v-model="draft"
                rows="2"
                :maxlength="maxLength"
                :disabled="!canWrite"
                :aria-invalid="refusal !== null || tooLong ? 'true' : undefined"
                :aria-describedby="refusal !== null ? errorId : undefined"
                placeholder="Schreib etwas in die Runde …"
                class="w-full resize-none rounded-md border border-border bg-surface px-3 py-2 text-sm text-text focus-visible:ring-2 focus-visible:ring-accent focus-visible:outline-none disabled:opacity-50"
                @keydown.enter="onEnter"
            />
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs text-text-muted">
                    {{ draft.length }} / {{ maxLength }}
                </span>
                <Button
                    type="submit"
                    size="sm"
                    :disabled="
                        !canWrite || sending || draft.trim() === '' || tooLong
                    "
                >
                    Senden
                </Button>
            </div>
            <p
                v-if="refusal"
                :id="errorId"
                class="text-sm text-danger"
                role="status"
            >
                {{ refusal }}
            </p>
            <!-- Outside the status paragraph on purpose: a live region
                 re-announces its whole content on every change, and this
                 number changes every second. `timer` is not live by
                 itself, so the sentence above is read once and the
                 countdown is there for whoever looks (.ai/rules/js.md). -->
            <p
                v-if="refusal && remaining !== null"
                class="text-xs text-text-muted"
            >
                <span role="timer">Noch {{ remaining }} s</span>
            </p>
        </form>
    </div>
</template>

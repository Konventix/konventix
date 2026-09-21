<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref, useId } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { formatDateTime } from '@/lib/datetime';

/**
 * The reporter's own view of their thread (design §8): what they wrote,
 * what came back, and a box to answer in while the report is open.
 *
 * **Not the console's `SupportThread`**, and the three differences are the
 * reason. That component offers a visibility choice, which a reporter does
 * not have — they can only write the kind their counterpart reads, and
 * offering the word "internal" on a public page would be offering a
 * mistake. It prints an author's ULID, which is a staff identifier and
 * means nothing here. And it tints internal notes, which is a distinction
 * this side must never be in a position to render at all: the server sends
 * external entries only (`scopeVisibleToReporter()`), so a component that
 * knew how to draw an internal one would be a component one prop away from
 * drawing one. What *is* shared is `SupportRequestMessageData` — one shape
 * for an entry, so the filter has exactly one place to be right.
 *
 * a11y (§12, ADR-0055): the textarea has a real `<label>`, its error is
 * linked through `aria-describedby` plus `aria-invalid`, and the result of
 * a submit is announced in a `role="status"` region that focus then moves
 * to — so a screen-reader user hears "Your reply was added" instead of
 * finding an emptied box and guessing. The entries are an ordered list of
 * `<article>`s, each headed by who wrote it and when, because a thread is a
 * sequence and a `<div>` soup says nothing about the order.
 */
const props = defineProps<{
    message: string;
    createdAt: string | null;
    messages: Konventix.Support.Data.SupportRequestMessageData[];
    canReply: boolean;
    replyUrl: string;
}>();

const bodyId = useId();
const statusId = useId();
const status = ref<HTMLElement | null>(null);
const announcement = ref('');

const form = useForm({ body: '' });

// ADR-0038: a half-typed answer about a broken purchase is worth a prompt
// before the tab goes.
useUnsavedChanges(() => form.isDirty);

function submit(): void {
    announcement.value = '';

    form.post(props.replyUrl, {
        preserveScroll: true,
        // The reply lands as a redirect back to this same page, and
        // `preserveState` is what keeps *this* component instance alive
        // across it — without it the page remounts, the announcement below
        // is wiped before anybody can read it, and the only feedback left
        // is that the box went empty.
        preserveState: true,
        onSuccess: () => {
            form.reset();
            announce('Your reply was added to this report.');
        },
        onError: (errors) => {
            announce(errors.body ?? 'Your reply could not be added.');
        },
    });
}

/**
 * Say it, then put the cursor on it. The live region alone covers a screen
 * reader; moving focus is what covers everybody else, because the reply box
 * is empty again and the page otherwise looks exactly as it did.
 */
function announce(sentence: string): void {
    announcement.value = sentence;

    void nextTick(() => status.value?.focus());
}
</script>

<template>
    <section
        class="flex flex-col gap-4"
        aria-labelledby="support-thread-heading"
    >
        <h2 id="support-thread-heading" class="text-base font-medium">
            The conversation
        </h2>

        <article
            class="flex flex-col gap-1 rounded-xl border border-border bg-surface-raised p-4"
        >
            <p class="text-xs text-text-muted">
                You ·
                <time :datetime="props.createdAt ?? undefined">
                    {{ formatDateTime(props.createdAt) }}
                </time>
            </p>
            <p class="text-sm whitespace-pre-wrap">{{ props.message }}</p>
        </article>

        <ol v-if="props.messages.length > 0" class="flex flex-col gap-3">
            <li v-for="entry in props.messages" :key="entry.id">
                <article
                    class="flex flex-col gap-1 rounded-xl border border-border p-4"
                    :class="
                        entry.isFromReporter ? 'bg-surface-raised' : 'bg-subtle'
                    "
                >
                    <p class="text-xs text-text-muted">
                        <span class="font-medium text-text">
                            {{ entry.isFromReporter ? 'You' : 'Support' }}
                        </span>
                        ·
                        <time :datetime="entry.createdAt ?? undefined">
                            {{ formatDateTime(entry.createdAt) }}
                        </time>
                    </p>
                    <p class="text-sm whitespace-pre-wrap">{{ entry.body }}</p>
                </article>
            </li>
        </ol>

        <p
            :id="statusId"
            ref="status"
            tabindex="-1"
            role="status"
            class="text-sm text-text-muted focus-visible:ring-2 focus-visible:ring-accent focus-visible:outline-none"
        >
            {{ announcement }}
        </p>

        <form
            v-if="props.canReply"
            class="flex flex-col gap-3 border-t border-border pt-4"
            @submit.prevent="submit"
        >
            <div class="grid gap-2">
                <Label :for="bodyId">Add something to this report</Label>
                <Textarea
                    :id="bodyId"
                    v-model="form.body"
                    rows="4"
                    maxlength="4000"
                    :aria-invalid="form.errors.body ? 'true' : undefined"
                    :aria-describedby="
                        form.errors.body ? `${bodyId}-error` : undefined
                    "
                />
                <div :id="`${bodyId}-error`">
                    <InputError :message="form.errors.body" />
                </div>
            </div>

            <div class="flex items-center gap-3">
                <Button
                    type="submit"
                    :disabled="form.processing || form.body.trim() === ''"
                >
                    Send reply
                </Button>
                <span
                    v-if="form.isDirty"
                    role="status"
                    class="text-xs text-warning"
                >
                    Unsaved changes
                </span>
            </div>
        </form>

        <p v-else class="text-sm text-text-muted">
            This report is closed, so it takes no further replies. If it is
            still happening, please file a new one.
        </p>
    </section>
</template>

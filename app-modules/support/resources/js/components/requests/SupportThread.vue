<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { formatDateTime } from '@/lib/datetime';
import messageRoutes from '@/routes/admin/support/requests/messages';

/**
 * The report and everything said about it since (design §4.2, §9.2).
 *
 * The one thing this component must never get wrong is which entries the
 * reporter can read. So the two kinds are rendered as visibly different
 * things — an internal note is tinted and labelled, a reply is not — and
 * the form makes the reader choose before it will submit, rather than
 * defaulting to either. A default would be the setting people stop seeing.
 *
 * The first entry is the report itself, which is a column on the request
 * and not a row in the thread; it is shown here anyway, because a thread
 * that starts with the first reply reads as if the answer came first.
 */
const props = defineProps<{
    requestId: string;
    message: string;
    createdAt: string | null;
    reporterLabel: string;
    messages: Konventix.Support.Data.SupportRequestMessageData[];
    canTriage: boolean;
}>();

const form = useForm<{
    visibility: Konventix.Support.Enums.MessageVisibility | '';
    body: string;
}>({
    visibility: '',
    body: '',
});

// ADR-0038: a half-typed reply to somebody whose checkout broke is worth a
// prompt before the tab goes.
useUnsavedChanges(() => form.isDirty);

function submit(): void {
    form.post(messageRoutes.store(props.requestId).url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>The report</CardTitle>
        </CardHeader>
        <CardContent class="flex flex-col gap-4">
            <div
                class="flex flex-col gap-1 rounded-md border border-border p-3"
            >
                <p class="text-xs text-text-muted">
                    {{ props.reporterLabel }} ·
                    <time :datetime="props.createdAt ?? undefined">
                        {{ formatDateTime(props.createdAt) }}
                    </time>
                </p>
                <p class="text-sm whitespace-pre-wrap">{{ props.message }}</p>
            </div>

            <div
                v-for="entry in props.messages"
                :key="entry.id"
                class="flex flex-col gap-1 rounded-md border p-3"
                :class="
                    entry.visibility === 'internal'
                        ? 'border-warning/40 bg-warning/5'
                        : 'border-border'
                "
            >
                <p class="text-xs text-text-muted">
                    <span
                        v-if="entry.visibility === 'internal'"
                        class="font-medium text-warning"
                    >
                        Internal note ·
                    </span>
                    <span v-else class="font-medium text-text">
                        Reply to the reporter ·
                    </span>
                    <span class="font-mono">
                        {{
                            entry.isFromReporter
                                ? props.reporterLabel
                                : entry.authorUserId
                        }}
                    </span>
                    ·
                    <time :datetime="entry.createdAt ?? undefined">
                        {{ formatDateTime(entry.createdAt) }}
                    </time>
                </p>
                <p class="text-sm whitespace-pre-wrap">{{ entry.body }}</p>
            </div>

            <form
                v-if="props.canTriage"
                class="flex flex-col gap-3 border-t border-border pt-4"
                @submit.prevent="submit"
            >
                <div class="grid gap-2">
                    <Label for="support-message-visibility"
                        >Who reads this</Label
                    >
                    <select
                        id="support-message-visibility"
                        v-model="form.visibility"
                        class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                        :aria-invalid="
                            form.errors.visibility ? 'true' : undefined
                        "
                        aria-describedby="support-message-visibility-hint"
                    >
                        <option value="">Choose…</option>
                        <option value="internal">
                            Internal note — staff only, forever
                        </option>
                        <option value="external">
                            Reply to the reporter — they will read it
                        </option>
                    </select>
                    <p
                        id="support-message-visibility-hint"
                        class="text-xs text-text-muted"
                    >
                        There is no default on purpose: an internal note that
                        reaches a reporter is the one mistake this thread can
                        make.
                    </p>
                    <InputError :message="form.errors.visibility" />
                </div>

                <div class="grid gap-2">
                    <Label for="support-message-body">Message</Label>
                    <Textarea
                        id="support-message-body"
                        v-model="form.body"
                        rows="4"
                        :aria-invalid="form.errors.body ? 'true' : undefined"
                    />
                    <InputError :message="form.errors.body" />
                </div>

                <div class="flex items-center gap-3">
                    <Button
                        type="submit"
                        :disabled="
                            form.processing ||
                            form.visibility === '' ||
                            form.body.trim() === ''
                        "
                    >
                        Add to thread
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
        </CardContent>
    </Card>
</template>

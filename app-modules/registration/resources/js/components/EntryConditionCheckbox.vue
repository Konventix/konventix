<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import {
    entryConditionDescribedBy,
    entryConditionErrorId,
    entryConditionHintId,
    entryConditionInputId,
} from './entryConditions';
import type { EntryConditionField } from './entryConditions';

/**
 * One declaration, as the registrant sees it (ADR-0066 §6.2, §9.3;
 * ADR-0055, ADR-0008).
 *
 * The rules this component exists to keep in one place:
 *
 * - the **full** `assertionText` is the `<label>`, verbatim. Never a
 *   truncated sentence with the rest behind a link — somebody who cannot
 *   read what they are agreeing to has not agreed to it.
 * - the document hint and the error are named by `aria-describedby`, and
 *   the input carries `aria-invalid` when it was refused.
 * - a native `<input type="checkbox">` rather than the shadcn control: the
 *   form posts through Inertia's `<Form>` with plain field names, and a
 *   button-with-a-role is one indirection too many on the page where a
 *   consumer concludes a contract.
 *
 * It is used twice: on the public form, and in the console editor's live
 * preview, so an operator writing the sentence sees exactly the checkbox
 * their registrants will see.
 */
const props = withDefaults(
    defineProps<{
        condition: EntryConditionField;
        modelValue: boolean;
        error?: string | null;
        /** The preview is the same checkbox, minus the ability to tick it. */
        disabled?: boolean;
        name?: string;
    }>(),
    { error: null, disabled: false, name: undefined },
);

const emit = defineEmits<{ 'update:modelValue': [boolean] }>();

function onChange(event: Event): void {
    emit('update:modelValue', (event.target as HTMLInputElement).checked);
}
</script>

<template>
    <div class="flex items-start gap-3">
        <input
            :id="entryConditionInputId(props.condition.id)"
            type="checkbox"
            :name="props.name"
            :value="props.condition.id"
            :checked="props.modelValue"
            :disabled="props.disabled"
            :aria-invalid="props.error ? 'true' : undefined"
            :aria-describedby="
                entryConditionDescribedBy(props.condition.id, {
                    hasHint: props.condition.documentHint !== null,
                    hasError: Boolean(props.error),
                })
            "
            class="mt-1 size-4 shrink-0 rounded border-border text-accent focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:outline-none"
            @change="onChange"
        />
        <div class="grid gap-1">
            <label
                :for="entryConditionInputId(props.condition.id)"
                class="text-sm text-text"
            >
                {{ props.condition.assertionText }}
            </label>
            <p
                v-if="props.condition.documentHint"
                :id="entryConditionHintId(props.condition.id)"
                class="text-xs text-text-muted"
            >
                {{ props.condition.documentHint }}
            </p>
            <InputError
                :id="entryConditionErrorId(props.condition.id)"
                :message="props.error ?? undefined"
            />
        </div>
    </div>
</template>

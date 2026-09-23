<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    formatDateTime,
    toInstant,
    viewerTimezone,
    withZoneName,
} from '@/lib/datetime';

/**
 * One way to type a moment (#242).
 *
 * Three pages had grown their own version of the same field — the policy
 * version's effective date (#202), a registration type's sales window (#237),
 * a content entry's publish moment (#246) — each with its own label wiring,
 * its own zone hint and its own echo line, and each one a place the next
 * `datetime-local` bug could be fixed in only two of.
 *
 * The value is the wall clock the control speaks, `YYYY-MM-DDTHH:MM`, which
 * is what the pages already hold: `toLocalDateTimeInput()` puts a stored
 * instant into it and `toInstant()` resolves it back on submit. The field
 * does not convert on the page's behalf, because a form posts its own shape
 * and half of them post a null for "not set".
 *
 * What the field does own is the part every one of them got slightly
 * differently: the zone the typed digits belong to — the *reader's*, never
 * the application's — and the echo that reads the instant back, so the
 * operator sees what they are about to store rather than what they typed.
 */
const model = defineModel<string>({ required: true });

const props = withDefaults(
    defineProps<{
        id: string;
        label: string;
        /** Said after the zone hint — what this particular moment means. */
        hint?: string;
        error?: string;
        required?: boolean;
        disabled?: boolean;
    }>(),
    { hint: undefined, error: undefined, required: false, disabled: false },
);

const hintId = computed(() => `${props.id}-hint`);
const errorId = computed(() => `${props.id}-error`);

// Both ids, not one or the other: the hint is where the reader's zone and the
// echo live, and losing it at the moment the field is rejected takes the
// context away from exactly the person who needs it most (ADR-0055). The hint
// paragraph here is unconditional — it always names the zone — so `hintId`
// never dangles.
const describedBy = computed(() =>
    props.error ? `${hintId.value} ${errorId.value}` : hintId.value,
);

/**
 * The instant the typed wall clock resolves to, named with its zone — the
 * one thing a bare `datetime-local` cannot say for itself. Empty while the
 * field is, and while what is in it is not yet a date.
 */
const echo = computed(() => {
    const instant = toInstant(model.value);

    return instant === null ? '' : formatDateTime(instant, withZoneName);
});
</script>

<template>
    <div class="grid gap-2">
        <Label :for="id">{{ label }}</Label>
        <Input
            :id="id"
            v-model="model"
            type="datetime-local"
            :required="required"
            :disabled="disabled"
            :aria-invalid="error ? true : undefined"
            :aria-describedby="describedBy"
        />
        <p :id="hintId" class="text-xs text-text-muted">
            {{
                $t('Times in your timezone (:zone).', {
                    zone: viewerTimezone(),
                })
            }}
            <template v-if="echo">
                =
                {{
                    $t(
                        ':moment — a time that does not exist on that date moves to the next one.',
                        { moment: echo },
                    )
                }}
            </template>
            <template v-if="hint"> {{ hint }}</template>
        </p>
        <InputError :id="errorId" :message="error" />
    </div>
</template>

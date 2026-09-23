<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatTime, viewerTimezone } from '@/lib/datetime';

/**
 * A time of day, typed once and the same way everywhere (#242).
 *
 * The value is `HH:MM` on a 24-hour clock, which is what `<input type="time">`
 * speaks whatever it displays — the operator's 12-hour setting changes how the
 * time is *echoed* and printed, never what is submitted.
 *
 * A time on its own is a wall clock, not an instant: it has no date to attach
 * a zone offset to. The hint names the reader's zone all the same, because a
 * time that will be paired with a date on the server is read on somebody's
 * clock and it should be clear whose.
 */
const model = defineModel<string>({ required: true });

const props = withDefaults(
    defineProps<{
        id: string;
        label: string;
        hint?: string;
        error?: string;
        required?: boolean;
        /** Say the zone — for a time that will be paired with a date. */
        zoned?: boolean;
    }>(),
    { hint: undefined, error: undefined, required: false, zoned: false },
);

const hintId = computed(() => `${props.id}-hint`);
const errorId = computed(() => `${props.id}-error`);

// Whether there is a hint paragraph at all: an empty field has no echo, and
// pointing `aria-describedby` at an element that is not in the document is a
// dangling IDREF (harmless, but three files spelling one rule three ways is
// how the fourth gets it wrong).
const hasHint = computed(
    () => echo.value !== '' || props.hint !== undefined || props.zoned,
);

// Both ids, not one or the other: the hint is where the reader's zone and the
// echo live, and losing it at the moment the field is rejected takes the
// context away from exactly the person who needs it most (ADR-0055).
const describedBy = computed(() =>
    [hasHint.value ? hintId.value : null, props.error ? errorId.value : null]
        .filter((id): id is string => id !== null)
        .join(' '),
);

// `formatTime()` writes a bare `HH:MM` out as typed rather than converting
// it: a time with no date has no offset to convert against.
const echo = computed(() => formatTime(model.value));
</script>

<template>
    <div class="grid gap-2">
        <Label :for="id">{{ label }}</Label>
        <Input
            :id="id"
            v-model="model"
            type="time"
            :required="required"
            :aria-invalid="error ? true : undefined"
            :aria-describedby="describedBy || undefined"
        />
        <p
            v-if="echo || hint || zoned"
            :id="hintId"
            class="text-xs text-text-muted"
        >
            <template v-if="echo">= {{ echo }}. </template>
            <template v-if="zoned"
                >{{
                    $t('Times in your timezone (:zone).', {
                        zone: viewerTimezone(),
                    })
                }}
            </template>
            <template v-if="hint">{{ hint }}</template>
        </p>
        <InputError :id="errorId" :message="error" />
    </div>
</template>

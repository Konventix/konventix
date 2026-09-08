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
            :aria-describedby="error ? errorId : hintId"
        />
        <p :id="hintId" class="text-xs text-text-muted">
            <template v-if="echo">= {{ echo }}. </template>
            <template v-if="zoned"
                >Times in your timezone ({{ viewerTimezone() }}).
            </template>
            <template v-if="hint">{{ hint }}</template>
        </p>
        <InputError :id="errorId" :message="error" />
    </div>
</template>

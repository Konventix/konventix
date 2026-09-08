<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/datetime';

/**
 * A calendar date, typed once and the same way everywhere (#242).
 *
 * The value is `YYYY-MM-DD`, which is what `<input type="date">` speaks and
 * what a `date` column stores — a due date, an issue date, a deadline. There
 * is deliberately no zone anywhere near it: a day is the same day in every
 * zone, and reading one as an instant is what put a repayment deadline a day
 * early for a reader west of Greenwich (#186/#197).
 *
 * The browser renders the control itself in its own locale and no attribute
 * changes that, so the operator's own format (`DD.MM.YYYY`, `MM/DD/YYYY`,
 * `YYYY-MM-DD`) is echoed underneath instead — which is also the shape the
 * date will have everywhere else it is printed.
 */
const model = defineModel<string>({ required: true });

const props = withDefaults(
    defineProps<{
        id: string;
        label: string;
        hint?: string;
        error?: string;
        required?: boolean;
        min?: string;
        max?: string;
    }>(),
    {
        hint: undefined,
        error: undefined,
        required: false,
        min: undefined,
        max: undefined,
    },
);

const hintId = computed(() => `${props.id}-hint`);
const errorId = computed(() => `${props.id}-error`);
const echo = computed(() => formatDate(model.value));
</script>

<template>
    <div class="grid gap-2">
        <Label :for="id">{{ label }}</Label>
        <Input
            :id="id"
            v-model="model"
            type="date"
            :min="min"
            :max="max"
            :required="required"
            :aria-invalid="error ? true : undefined"
            :aria-describedby="error ? errorId : hintId"
        />
        <p :id="hintId" class="text-xs text-text-muted">
            <template v-if="echo">= {{ echo }}. </template>
            <template v-if="hint">{{ hint }}</template>
        </p>
        <InputError :id="errorId" :message="error" />
    </div>
</template>

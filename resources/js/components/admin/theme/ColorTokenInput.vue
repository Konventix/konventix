<script setup lang="ts">
/* eslint-disable konventix/no-palette-colors -- raw color values are this
   control's domain: it edits the tokens ADR-0008's rule points everyone at. */
/**
 * One catalog token's control: a native color swatch plus a text input that
 * accepts any CSS color shape the server does (hex, rgb(), hsl()). Empty
 * string means "no override — inherit the platform default."
 */
import { computed } from 'vue';
import { Input } from '@/components/ui/input';
import { toHexColor } from '@/lib/contrast';

const props = defineProps<{
    id?: string;
    label: string;
    tokenName: string;
    fallback: string;
    modelValue: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

/**
 * <input type="color"> only understands 6-digit hex, but the effective value
 * can be any accepted shape — the catalog defaults are hsl() (resources/css/
 * app.css) and the operator may type rgb()/hsl() directly. Convert whatever
 * currently applies (override, else platform default) so the swatch always
 * shows the real color; black only remains for unparseable input.
 */
const swatchValue = computed<string>(
    () =>
        toHexColor(props.modelValue) ?? toHexColor(props.fallback) ?? '#000000',
);

function reset(): void {
    emit('update:modelValue', '');
}
</script>

<template>
    <div class="flex items-center gap-2">
        <input
            :id="id"
            type="color"
            :value="swatchValue"
            class="h-9 w-9 shrink-0 cursor-pointer rounded-md border border-input bg-transparent p-0.5"
            :aria-label="label"
            @input="
                emit(
                    'update:modelValue',
                    ($event.target as HTMLInputElement).value,
                )
            "
        />
        <Input
            :model-value="modelValue"
            :placeholder="fallback"
            class="h-9 flex-1 font-mono text-xs"
            @update:model-value="
                (value) => emit('update:modelValue', String(value))
            "
        />
        <button
            v-if="modelValue !== ''"
            type="button"
            class="shrink-0 text-xs text-text-muted hover:text-text"
            @click="reset"
        >
            Reset
        </button>
    </div>
</template>

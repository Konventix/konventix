<script setup lang="ts">
/**
 * One catalog token's control: a native color swatch plus a text input that
 * accepts any CSS color shape the server does (hex, rgb(), hsl()). Empty
 * string means "no override — inherit the platform default."
 */
import { computed } from 'vue';
import { Input } from '@/components/ui/input';

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

const HEX_RE = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i;

/**
 * <input type="color"> only understands 6-digit hex. The catalog defaults
 * are hsl() (resources/css/app.css), so the swatch shows a plain black
 * placeholder until the operator either picks a color (which always emits
 * hex) or types a hex value directly — the text input beside it is the
 * source of truth for every other color shape.
 */
const swatchValue = computed<string>(() => {
    const current = props.modelValue.trim();

    if (HEX_RE.test(current)) {
        return current;
    }

    return HEX_RE.test(props.fallback) ? props.fallback : '#000000';
});

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
        >
        <Input
            :model-value="modelValue"
            :placeholder="fallback"
            class="h-9 flex-1 font-mono text-xs"
            @update:model-value="(value) => emit('update:modelValue', String(value))"
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

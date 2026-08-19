<script setup lang="ts">
/**
 * Renders every catalog group as a labeled grid of ColorTokenInput controls,
 * two-way bound to a flat token-name => override map (only overridden tokens
 * are keys — a reset removes the key rather than storing the default).
 */
import ColorTokenInput from '@/components/admin/theme/ColorTokenInput.vue';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    groups: Konventix.Platform.Data.ThemeTokenGroupData[];
    modelValue: Record<string, string>;
    /**
     * Which colour scheme's defaults sit behind the inputs as placeholders.
     * A dark accent is meant to look wrong next to the light default, so the
     * grid has to show the default the operator is actually editing against.
     */
    scheme?: 'light' | 'dark';
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: Record<string, string>): void;
}>();

function valueOf(name: string): string {
    return props.modelValue[name] ?? '';
}

function setToken(name: string, value: string): void {
    const next = { ...props.modelValue };

    if (value === '') {
        delete next[name];
    } else {
        next[name] = value;
    }

    emit('update:modelValue', next);
}
</script>

<template>
    <div class="space-y-6">
        <div v-for="group in groups" :key="group.key" class="space-y-3">
            <div
                class="text-xs font-semibold tracking-wide text-text-muted uppercase"
            >
                {{ group.label }}
            </div>
            <div class="grid gap-3">
                <div
                    v-for="token in group.tokens"
                    :key="token.name"
                    class="grid gap-1.5"
                >
                    <Label
                        :for="`token-${token.name}`"
                        class="text-xs font-normal"
                    >
                        {{ token.label }}
                        <code class="ml-1 text-[10px] text-text-muted/70"
                            >--{{ token.name }}</code
                        >
                    </Label>
                    <ColorTokenInput
                        :id="`token-${token.name}`"
                        :token-name="token.name"
                        :label="token.label"
                        :fallback="props.scheme === 'dark' ? token.dark : token.light"
                        :model-value="valueOf(token.name)"
                        @update:model-value="
                            (value) => setToken(token.name, value)
                        "
                    />
                </div>
            </div>
        </div>
    </div>
</template>

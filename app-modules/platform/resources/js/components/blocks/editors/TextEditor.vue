<script setup lang="ts">
import { useId } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BlockEditorProps } from '@/types/blocks';

type Data = Konventix.Platform.Data.Blocks.TextBlockData;

const props = defineProps<BlockEditorProps<Data>>();
const emit = defineEmits<{ 'update:modelValue': [value: Data] }>();

function set<K extends keyof Data>(key: K, value: Data[K]): void {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
}

const id = useId();
</script>

<template>
    <div class="grid gap-4">
        <div class="grid gap-2">
            <Label :for="`${id}-heading`">Heading (optional)</Label>
            <Input
                :id="`${id}-heading`"
                :model-value="props.modelValue.heading ?? ''"
                @update:model-value="(v) => set('heading', String(v) || null)"
            />
            <InputError :message="props.errors.heading" />
        </div>
        <div class="grid gap-2">
            <Label :for="`${id}-body`">Body</Label>
            <textarea
                :id="`${id}-body`"
                :value="props.modelValue.body"
                rows="6"
                class="rounded-md border border-border bg-surface px-3 py-2 text-sm"
                @input="
                    set('body', ($event.target as HTMLTextAreaElement).value)
                "
            />
            <InputError :message="props.errors.body" />
        </div>
    </div>
</template>

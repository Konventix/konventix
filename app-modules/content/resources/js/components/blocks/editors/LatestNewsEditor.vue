<script setup lang="ts">
import { useId } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BlockEditorProps } from '@/types/blocks';

type Data = Konventix.Content.Data.LatestNewsBlockData;

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
            <Label :for="`${id}-heading`">{{ $t('Heading (optional)') }}</Label>
            <Input
                :id="`${id}-heading`"
                :model-value="props.modelValue.heading ?? ''"
                @update:model-value="(v) => set('heading', String(v) || null)"
            />
            <InputError :message="props.errors.heading" />
        </div>
        <div class="grid gap-2">
            <Label :for="`${id}-limit`">{{ $t('How many entries') }}</Label>
            <Input
                :id="`${id}-limit`"
                type="number"
                min="1"
                max="12"
                :model-value="props.modelValue.limit"
                @update:model-value="(v) => set('limit', Number(v))"
            />
            <InputError :message="props.errors.limit" />
        </div>
    </div>
</template>

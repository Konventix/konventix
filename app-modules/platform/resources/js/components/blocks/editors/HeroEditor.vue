<script setup lang="ts">
import { useId } from 'vue';
import MediaPicker from '@/components/admin/MediaPicker.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAdminCapabilities } from '@/composables/useAdminCapabilities';
import type { BlockEditorProps } from '@/types/blocks';

type Data = Konventix.Platform.Data.Blocks.HeroBlockData;

const props = defineProps<BlockEditorProps<Data>>();
const { canManageMedia } = useAdminCapabilities();
const emit = defineEmits<{ 'update:modelValue': [value: Data] }>();

function set<K extends keyof Data>(key: K, value: Data[K]): void {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
}

const id = useId();
</script>

<template>
    <div class="grid gap-4">
        <div class="grid gap-2">
            <Label :for="`${id}-heading`">{{ $t('Heading') }}</Label>
            <Input
                :id="`${id}-heading`"
                :model-value="props.modelValue.heading"
                @update:model-value="(v) => set('heading', String(v))"
            />
            <InputError :message="props.errors.heading" />
        </div>
        <div class="grid gap-2">
            <Label :for="`${id}-subheading`">{{
                $t('Subheading (optional)')
            }}</Label>
            <Input
                :id="`${id}-subheading`"
                :model-value="props.modelValue.subheading ?? ''"
                @update:model-value="
                    (v) => set('subheading', String(v) || null)
                "
            />
            <InputError :message="props.errors.subheading" />
        </div>
        <div class="grid gap-2">
            <Label :for="`${id}-cta-label`">{{
                $t('Button label (optional)')
            }}</Label>
            <Input
                :id="`${id}-cta-label`"
                :model-value="props.modelValue.ctaLabel ?? ''"
                @update:model-value="(v) => set('ctaLabel', String(v) || null)"
            />
            <InputError :message="props.errors.ctaLabel" />
        </div>
        <div class="grid gap-2">
            <Label :for="`${id}-cta-url`">{{
                $t('Button link (optional)')
            }}</Label>
            <Input
                :id="`${id}-cta-url`"
                :model-value="props.modelValue.ctaUrl ?? ''"
                @update:model-value="(v) => set('ctaUrl', String(v) || null)"
            />
            <InputError :message="props.errors.ctaUrl" />
        </div>
        <div class="grid gap-2">
            <MediaPicker
                :label="$t('Image')"
                :model-value="props.modelValue.imageMediaId"
                :media="props.context.media"
                :brand-id="props.context.brandId ?? null"
                :can-upload="canManageMedia"
                :described-by="
                    props.errors.imageMediaId ? `${id}-image-error` : undefined
                "
                @update:model-value="(v) => set('imageMediaId', v)"
            />
            <InputError
                :id="`${id}-image-error`"
                :message="props.errors.imageMediaId"
            />
        </div>
    </div>
</template>

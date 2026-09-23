<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { useId } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BlockEditorProps } from '@/types/blocks';

type Data = Konventix.Platform.Data.Blocks.SocialLinksBlockData;
type Link = Konventix.Platform.Data.Blocks.SocialLinkData;

const props = defineProps<BlockEditorProps<Data>>();
const emit = defineEmits<{ 'update:modelValue': [value: Data] }>();

/** Hardcoded the same as `SocialLinkData::NETWORKS` — the Data class is the law. */
const networks = [
    'github',
    'x',
    'facebook',
    'instagram',
    'youtube',
    'twitch',
    'discord',
    'mastodon',
    'linkedin',
    'website',
] as const;

const id = useId();

function setLinks(links: Link[]): void {
    emit('update:modelValue', { ...props.modelValue, links });
}

function setLink<K extends keyof Link>(
    index: number,
    key: K,
    value: Link[K],
): void {
    setLinks(
        props.modelValue.links.map((link, i) =>
            i === index ? { ...link, [key]: value } : link,
        ),
    );
}

function addLink(): void {
    setLinks([
        ...props.modelValue.links,
        { network: 'website', url: '', label: null },
    ]);
}

function removeLink(index: number): void {
    setLinks(props.modelValue.links.filter((_, i) => i !== index));
}
</script>

<template>
    <div class="grid gap-4">
        <div
            v-for="(link, index) in props.modelValue.links"
            :key="index"
            class="grid gap-3 rounded-md border border-border p-3"
        >
            <div class="flex items-center justify-between">
                <p class="text-sm font-medium text-text">
                    {{ $t('Link :number', { number: String(index + 1) }) }}
                </p>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    :aria-label="
                        $t('Remove link :number', { number: String(index + 1) })
                    "
                    @click="removeLink(index)"
                >
                    <Trash2 class="size-4" />
                </Button>
            </div>

            <div class="grid gap-2">
                <Label :for="`${id}-network-${index}`">{{
                    $t('Network')
                }}</Label>
                <select
                    :id="`${id}-network-${index}`"
                    class="rounded-md border border-border bg-surface px-3 py-2 text-sm"
                    :value="link.network"
                    @change="
                        setLink(
                            index,
                            'network',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option
                        v-for="network in networks"
                        :key="network"
                        :value="network"
                    >
                        {{ network }}
                    </option>
                </select>
                <InputError :message="props.errors[`links.${index}.network`]" />
            </div>

            <div class="grid gap-2">
                <Label :for="`${id}-url-${index}`">{{ $t('URL') }}</Label>
                <Input
                    :id="`${id}-url-${index}`"
                    :model-value="link.url"
                    placeholder="https://github.com/konventix"
                    @update:model-value="
                        (v) => setLink(index, 'url', String(v))
                    "
                />
                <InputError :message="props.errors[`links.${index}.url`]" />
            </div>

            <div class="grid gap-2">
                <Label :for="`${id}-label-${index}`">{{
                    $t('Label (optional)')
                }}</Label>
                <Input
                    :id="`${id}-label-${index}`"
                    :model-value="link.label ?? ''"
                    @update:model-value="
                        (v) => setLink(index, 'label', String(v) || null)
                    "
                />
                <InputError :message="props.errors[`links.${index}.label`]" />
            </div>
        </div>

        <Button
            type="button"
            variant="secondary"
            size="sm"
            class="self-start"
            @click="addLink"
        >
            <Plus class="size-4" />
            {{ $t('Add link') }}
        </Button>
    </div>
</template>

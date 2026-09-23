<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { useId } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BlockEditorProps } from '@/types/blocks';

type Data = Konventix.Platform.Data.Blocks.CardsBlockData;
type Card = Konventix.Platform.Data.Blocks.CardItemData;

const props = defineProps<BlockEditorProps<Data>>();
const emit = defineEmits<{ 'update:modelValue': [value: Data] }>();

/** The eight icon keys `CardsBlock.vue` knows how to render. */
const iconOptions = [
    'calendar',
    'clock',
    'map-pin',
    'megaphone',
    'sparkles',
    'ticket',
    'trophy',
    'users',
] as const;

/** The words the select shows for those keys, looked up when rendered. */
function iconLabel(icon: (typeof iconOptions)[number]): string {
    const labels: Record<(typeof iconOptions)[number], string> = {
        calendar: trans('Calendar'),
        clock: trans('Clock'),
        'map-pin': trans('Map pin'),
        megaphone: trans('Megaphone'),
        sparkles: trans('Sparkles'),
        ticket: trans('Ticket'),
        trophy: trans('Trophy'),
        users: trans('People'),
    };

    return labels[icon];
}

const id = useId();

function setCards(cards: Card[]): void {
    emit('update:modelValue', { ...props.modelValue, cards });
}

function setCard<K extends keyof Card>(
    index: number,
    key: K,
    value: Card[K],
): void {
    setCards(
        props.modelValue.cards.map((card, i) =>
            i === index ? { ...card, [key]: value } : card,
        ),
    );
}

function addCard(): void {
    setCards([...props.modelValue.cards, { title: '', body: '', icon: null }]);
}

function removeCard(index: number): void {
    setCards(props.modelValue.cards.filter((_, i) => i !== index));
}
</script>

<template>
    <div class="grid gap-4">
        <div
            v-for="(card, index) in props.modelValue.cards"
            :key="index"
            class="grid gap-3 rounded-md border border-border p-3"
        >
            <div class="flex items-center justify-between">
                <p class="text-sm font-medium text-text">
                    {{ $t('Card :number', { number: String(index + 1) }) }}
                </p>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    :aria-label="
                        $t('Remove card :number', { number: String(index + 1) })
                    "
                    @click="removeCard(index)"
                >
                    <Trash2 class="size-4" />
                </Button>
            </div>

            <div class="grid gap-2">
                <Label :for="`${id}-title-${index}`">{{ $t('Title') }}</Label>
                <Input
                    :id="`${id}-title-${index}`"
                    :model-value="card.title"
                    @update:model-value="
                        (v) => setCard(index, 'title', String(v))
                    "
                />
                <InputError :message="props.errors[`cards.${index}.title`]" />
            </div>

            <div class="grid gap-2">
                <Label :for="`${id}-body-${index}`">{{ $t('Body') }}</Label>
                <textarea
                    :id="`${id}-body-${index}`"
                    :value="card.body"
                    rows="3"
                    class="rounded-md border border-border bg-surface px-3 py-2 text-sm"
                    @input="
                        setCard(
                            index,
                            'body',
                            ($event.target as HTMLTextAreaElement).value,
                        )
                    "
                />
                <InputError :message="props.errors[`cards.${index}.body`]" />
            </div>

            <div class="grid gap-2">
                <Label :for="`${id}-icon-${index}`">{{ $t('Icon') }}</Label>
                <select
                    :id="`${id}-icon-${index}`"
                    class="rounded-md border border-border bg-surface px-3 py-2 text-sm"
                    :value="card.icon ?? ''"
                    @change="
                        setCard(
                            index,
                            'icon',
                            ($event.target as HTMLSelectElement).value || null,
                        )
                    "
                >
                    <option value="">{{ $t('None') }}</option>
                    <option
                        v-for="icon in iconOptions"
                        :key="icon"
                        :value="icon"
                    >
                        {{ iconLabel(icon) }}
                    </option>
                </select>
                <InputError :message="props.errors[`cards.${index}.icon`]" />
            </div>
        </div>

        <Button
            type="button"
            variant="secondary"
            size="sm"
            class="self-start"
            @click="addCard"
        >
            <Plus class="size-4" />
            {{ $t('Add card') }}
        </Button>
    </div>
</template>

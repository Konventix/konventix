<script setup lang="ts">
import { useId } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BlockEditorProps } from '@/types/blocks';

/**
 * Editor half of the `tickets-for-event` block: the event picker Event's
 * `EventHeroEditor` uses (`context.events`, filled by
 * `ConsoleEventSources`), plus an optional heading. An empty heading is sent
 * as null so the server falls back to the event's name.
 */
type Data = Konventix.Registration.Data.TicketsForEventBlockData;

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
            <Label :for="`${id}-event`">{{ $t('Event') }}</Label>
            <select
                :id="`${id}-event`"
                class="rounded-md border border-border bg-surface px-3 py-2 text-sm"
                :value="props.modelValue.eventId"
                @change="
                    set('eventId', ($event.target as HTMLSelectElement).value)
                "
            >
                <option value="">{{ $t('Choose an event') }}</option>
                <option
                    v-for="event in props.context.events"
                    :key="event.id"
                    :value="event.id"
                >
                    {{ event.name }}
                </option>
            </select>
            <InputError :message="props.errors.eventId" />
            <p
                v-if="props.context.events.length === 0"
                class="text-sm text-text-muted"
            >
                {{ $t('No events on this brand yet.') }}
            </p>
        </div>
        <div class="grid gap-2">
            <Label :for="`${id}-heading`">{{ $t('Heading (optional)') }}</Label>
            <Input
                :id="`${id}-heading`"
                :model-value="props.modelValue.heading ?? ''"
                :placeholder="$t('Defaults to the event’s name')"
                @update:model-value="(v) => set('heading', String(v) || null)"
            />
            <InputError :message="props.errors.heading" />
        </div>
    </div>
</template>

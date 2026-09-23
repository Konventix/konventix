<script setup lang="ts">
/**
 * The operator's brand and event pick (ROADMAP slice 18). Held server-side
 * for the session; every cross-brand list reads it as its default filter.
 * Renders nothing but the brand select when no module offers events.
 */
import { router } from '@inertiajs/vue3';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useConsoleSelection } from '@/composables/useConsoleSelection';
import { update as updateSelection } from '@/routes/admin/selection';

const ALL = '__all__';

const selection = useConsoleSelection();

function submit(brandId: string | null, eventId: string | null): void {
    router.put(
        updateSelection().url,
        { brandId, eventId },
        { preserveScroll: true },
    );
}

function pickBrand(value: unknown): void {
    const brandId = value === ALL ? null : String(value);
    // A brand change drops the event: the old pick belongs to the old brand.
    submit(brandId, null);
}

function pickEvent(value: unknown): void {
    submit(
        selection.value?.brandId ?? null,
        value === ALL ? null : String(value),
    );
}
</script>

<template>
    <div v-if="selection" class="flex items-center gap-2">
        <label for="console-brand" class="sr-only">{{ $t('Brand') }}</label>
        <Select
            :model-value="selection.brandId ?? ALL"
            @update:model-value="pickBrand"
        >
            <SelectTrigger id="console-brand" class="w-44">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem :value="ALL">{{ $t('All brands') }}</SelectItem>
                <SelectItem
                    v-for="brand in selection.brands"
                    :key="brand.id"
                    :value="brand.id"
                >
                    {{ brand.name }}
                </SelectItem>
            </SelectContent>
        </Select>

        <template v-if="selection.eventsAvailable">
            <label for="console-event" class="sr-only">{{ $t('Event') }}</label>
            <Select
                :model-value="selection.eventId ?? ALL"
                @update:model-value="pickEvent"
            >
                <SelectTrigger id="console-event" class="w-52">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">{{ $t('All events') }}</SelectItem>
                    <SelectItem
                        v-for="event in selection.events"
                        :key="event.id"
                        :value="event.id"
                    >
                        {{ event.name }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </template>
    </div>
</template>

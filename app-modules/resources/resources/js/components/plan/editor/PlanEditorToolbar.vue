<script setup lang="ts">
import {
    Ban,
    Hand,
    Maximize,
    MousePointer2,
    Redo2,
    Square,
    Type,
    Undo2,
} from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import type { SnapStep } from './snap';
import { SNAP_STEPS } from './snap';
import type { PlanStore, Tool } from './usePlanStore';

/**
 * Tools, undo/redo, the grid step and "fit". Every button is a real button
 * with an accessible name and `aria-pressed` for the active tool, so the
 * keyboard path (.ai/rules/js.md) and the shortcut path meet in one state.
 */
const props = defineProps<{ store: PlanStore; snapStep: SnapStep }>();

const emit = defineEmits<{ 'update:snapStep': [step: SnapStep]; fit: [] }>();

const store = props.store;

// Computed, not a module constant: the labels are catalog lookups (ADR-0068).
const tools = computed<
    {
        tool: Tool;
        label: string;
        key: string;
        icon: typeof MousePointer2;
    }[]
>(() => [
    {
        tool: 'select',
        label: trans('Select (V)'),
        key: 'V',
        icon: MousePointer2,
    },
    { tool: 'place', label: trans('Add place (S)'), key: 'S', icon: Square },
    {
        tool: 'annotation',
        label: trans('Add annotation (L)'),
        key: 'L',
        icon: Type,
    },
    {
        tool: 'delete',
        label: trans('Delete on click (D)'),
        key: 'D',
        icon: Ban,
    },
    { tool: 'pan', label: trans('Pan (hold Space)'), key: 'Space', icon: Hand },
]);

function onSnapChange(event: Event): void {
    emit(
        'update:snapStep',
        Number((event.target as HTMLSelectElement).value) as SnapStep,
    );
}
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-2"
        role="toolbar"
        :aria-label="$t('Editor tools')"
    >
        <Button
            v-for="entry in tools"
            :key="entry.tool"
            type="button"
            size="icon-sm"
            :variant="store.tool.value === entry.tool ? 'default' : 'outline'"
            :aria-pressed="store.tool.value === entry.tool"
            :aria-label="entry.label"
            :title="entry.label"
            @click="store.tool.value = entry.tool"
        >
            <component :is="entry.icon" class="size-4" />
        </Button>

        <span class="mx-1 h-6 w-px bg-border" aria-hidden="true" />

        <Button
            type="button"
            size="icon-sm"
            variant="outline"
            :aria-label="$t('Undo (Ctrl+Z)')"
            :disabled="!store.canUndo.value"
            @click="store.undo()"
        >
            <Undo2 class="size-4" />
        </Button>
        <Button
            type="button"
            size="icon-sm"
            variant="outline"
            :aria-label="$t('Redo (Shift+Ctrl+Z)')"
            :disabled="!store.canRedo.value"
            @click="store.redo()"
        >
            <Redo2 class="size-4" />
        </Button>
        <Button
            type="button"
            size="icon-sm"
            variant="outline"
            :aria-label="$t('Fit plan into view')"
            @click="emit('fit')"
        >
            <Maximize class="size-4" />
        </Button>

        <label class="ml-auto flex items-center gap-2 text-sm text-text-muted">
            {{ $t('Grid') }}
            <select
                :value="snapStep"
                :aria-label="$t('Snap step in millimetres')"
                class="h-8 rounded-md border border-border bg-surface px-2 text-sm text-text"
                @change="onSnapChange"
            >
                <option v-for="step in SNAP_STEPS" :key="step" :value="step">
                    {{ step }} mm
                </option>
            </select>
        </label>
    </div>
</template>

<script setup lang="ts">
import { GripVertical, Plus, Trash2 } from '@lucide/vue';
import { computed, useId } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useDragReorder } from '@/composables/useDragReorder';
import { freshBlockData } from '@/lib/blockDefaults';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';
import type { BlockEditorContext } from '@/types/blocks';

/**
 * The block editor (ROADMAP slice 21a). Generic on purpose: it knows how to
 * add a block of a registered type, reorder blocks (drag, or arrow keys on
 * the grip), remove one, and hand each block to the editor component its
 * type named — it knows no field of any block. Position is the array index
 * and the whole list is part of the surrounding form's single submit.
 */
type Block = Konventix.Platform.Data.Blocks.BlockData;
type TypeOption = Konventix.Platform.Data.Blocks.BlockTypeOptionData;

const props = defineProps<{
    modelValue: Block[];
    types: TypeOption[];
    /** The form's errors, keyed `blocks.{i}.data.{field}` / `blocks.{i}.type`. */
    errors: Record<string, string>;
    context: BlockEditorContext;
    /** Prefix of this list's error keys; `blocks` unless the form nests it. */
    field?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: Block[]] }>();

// Unique per instance: two composers render on the footer page, and a
// static id would give both their handles the same `aria-describedby`.
const reorderHintId = useId();

const field = computed(() => props.field ?? 'blocks');

const typesByKey = computed(() =>
    Object.fromEntries(props.types.map((type) => [type.key, type])),
);

const {
    draggingIndex,
    onDragStart,
    onDragEnter,
    onDrop,
    onDragEnd,
    onHandleKeydown,
} = useDragReorder<Block>({
    get: () => props.modelValue,
    set: (next) => emit('update:modelValue', next),
});

function add(type: TypeOption): void {
    emit('update:modelValue', [
        ...props.modelValue,
        { type: type.key, data: freshBlockData(type.defaultData) },
    ]);
}

function remove(index: number): void {
    emit(
        'update:modelValue',
        props.modelValue.filter((_, i) => i !== index),
    );
}

function updateData(index: number, data: Record<string, unknown>): void {
    emit(
        'update:modelValue',
        props.modelValue.map((block, i) =>
            i === index ? { ...block, data } : block,
        ),
    );
}

/** The errors of one block, with the `blocks.{i}.data.` prefix stripped. */
function errorsFor(index: number): Record<string, string> {
    const prefix = `${field.value}.${index}.data.`;

    return Object.fromEntries(
        Object.entries(props.errors)
            .filter(([key]) => key.startsWith(prefix))
            .map(([key, message]) => [key.slice(prefix.length), message]),
    );
}

function editorFor(block: Block) {
    const type = typesByKey.value[block.type];

    return type ? resolveRegisteredComponent(type.editorComponent) : null;
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <p :id="reorderHintId" class="sr-only">
            Drag a block to reorder it, or focus its reorder handle and press
            the up or down arrow key.
        </p>

        <p
            v-if="props.modelValue.length === 0"
            class="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-text-muted"
        >
            No blocks yet — add one below.
        </p>

        <Card
            v-for="(block, index) in props.modelValue"
            :key="index"
            draggable="true"
            :class="draggingIndex === index && 'opacity-60'"
            @dragstart="onDragStart(index, $event)"
            @dragenter.prevent="onDragEnter(index)"
            @dragover.prevent
            @drop.prevent="onDrop"
            @dragend="onDragEnd"
        >
            <CardHeader class="flex flex-row items-center gap-2">
                <button
                    type="button"
                    class="cursor-grab rounded p-1 text-text-muted hover:text-text active:cursor-grabbing"
                    :aria-describedby="reorderHintId"
                    @keydown="onHandleKeydown(index, $event)"
                >
                    <GripVertical class="size-4" />
                    <span class="sr-only">
                        Reorder
                        {{ typesByKey[block.type]?.label ?? block.type }},
                        position {{ index + 1 }} of
                        {{ props.modelValue.length }}
                    </span>
                </button>
                <CardTitle class="flex-1 text-base">
                    {{ typesByKey[block.type]?.label ?? block.type }}
                </CardTitle>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    :aria-label="`Remove block ${index + 1}`"
                    @click="remove(index)"
                >
                    <Trash2 class="size-4" />
                </Button>
            </CardHeader>
            <CardContent>
                <p
                    v-if="props.errors[`${field}.${index}.type`]"
                    class="text-sm text-danger"
                >
                    {{ props.errors[`${field}.${index}.type`] }}
                </p>
                <component
                    :is="editorFor(block)"
                    v-if="editorFor(block)"
                    :model-value="block.data"
                    :errors="errorsFor(index)"
                    :context="props.context"
                    @update:model-value="
                        (data: Record<string, unknown>) =>
                            updateData(index, data)
                    "
                />
                <p v-else class="text-sm text-text-muted">
                    This block's editor is not available — its module may be
                    switched off for this brand.
                </p>
            </CardContent>
        </Card>

        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    class="self-start"
                >
                    <Plus class="size-4" />
                    Add block
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
                <DropdownMenuItem
                    v-for="type in props.types"
                    :key="type.key"
                    @select="add(type)"
                >
                    {{ type.label }}
                    <span class="ml-2 text-xs text-text-muted">{{
                        type.module
                    }}</span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>

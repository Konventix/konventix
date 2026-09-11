<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { GroupSpec } from './planEditing';

/**
 * "Add group": a linear row or a grid of places with a label schema
 * (design §5). The page places the result right of the plan, selects it
 * and switches to the select tool.
 */
const props = defineProps<{
    open: boolean;
    categories: Konventix.Resources.Data.CategoryData[];
    suggestedLabel: string;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
    create: [spec: GroupSpec];
}>();

const form = reactive({
    label: props.suggestedLabel,
    type: 'linear' as Konventix.Resources.Enums.LayoutKind,
    count: 16,
    rows: 2,
    cols: 8,
    spacing: 800,
    rowSpacing: 1000,
    startIndex: 1,
    rowSchema: '{row}',
    placeSchema: '{row}-{n:02}',
    resourceTypeId: props.categories[0]?.id ?? '',
});

watch(
    () => props.open,
    (open) => {
        if (open) {
            form.label = props.suggestedLabel;
            form.resourceTypeId =
                form.resourceTypeId || (props.categories[0]?.id ?? '');
        }
    },
);

function submit(): void {
    if (form.label.trim() === '' || form.resourceTypeId === '') {
        return;
    }

    emit('create', {
        label: form.label.trim(),
        layout: {
            type: form.type,
            count:
                form.type === 'linear'
                    ? Math.max(0, Math.round(form.count))
                    : null,
            rows:
                form.type === 'grid'
                    ? Math.max(0, Math.round(form.rows))
                    : null,
            cols:
                form.type === 'grid'
                    ? Math.max(0, Math.round(form.cols))
                    : null,
            spacing: Math.max(1, Math.round(form.spacing)),
            rowSpacing:
                form.type === 'grid'
                    ? Math.max(1, Math.round(form.rowSpacing))
                    : null,
            startIndex: Math.max(0, Math.round(form.startIndex)),
        },
        labelSchema: { row: form.rowSchema, place: form.placeSchema },
        resourceTypeId: form.resourceTypeId,
    });
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="max-h-[85vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Add a group</DialogTitle>
                <DialogDescription
                    >A row or a grid of places; the labels come from the schema.
                    Everything is in millimetres.</DialogDescription
                >
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <p
                    v-if="categories.length === 0"
                    class="rounded-md border border-warning/30 bg-warning/10 p-3 text-sm text-text"
                >
                    This event has no categories yet. Create one under
                    Categories first — every place needs one.
                </p>

                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1">
                        <Label for="group-label">Group label</Label>
                        <Input id="group-label" v-model="form.label" required />
                    </div>
                    <div class="grid gap-1">
                        <Label for="group-category">Category</Label>
                        <select
                            id="group-category"
                            v-model="form.resourceTypeId"
                            required
                            class="h-9 rounded-md border border-border bg-surface px-3 text-sm text-text"
                        >
                            <option
                                v-for="category in categories"
                                :key="category.id"
                                :value="category.id"
                            >
                                {{ category.name }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="grid gap-1">
                    <Label for="group-type">Layout</Label>
                    <select
                        id="group-type"
                        v-model="form.type"
                        class="h-9 rounded-md border border-border bg-surface px-3 text-sm text-text"
                    >
                        <option value="linear">Linear row</option>
                        <option value="grid">Grid</option>
                    </select>
                </div>

                <div
                    v-if="form.type === 'linear'"
                    class="grid grid-cols-2 gap-3"
                >
                    <div class="grid gap-1">
                        <Label for="group-count">Places</Label>
                        <Input
                            id="group-count"
                            v-model.number="form.count"
                            type="number"
                            min="1"
                            max="500"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label for="group-spacing">Spacing</Label>
                        <Input
                            id="group-spacing"
                            v-model.number="form.spacing"
                            type="number"
                            min="1"
                        />
                    </div>
                </div>
                <div v-else class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1">
                        <Label for="group-rows">Rows</Label>
                        <Input
                            id="group-rows"
                            v-model.number="form.rows"
                            type="number"
                            min="1"
                            max="100"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label for="group-cols">Columns</Label>
                        <Input
                            id="group-cols"
                            v-model.number="form.cols"
                            type="number"
                            min="1"
                            max="100"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label for="group-spacing-grid">Column spacing</Label>
                        <Input
                            id="group-spacing-grid"
                            v-model.number="form.spacing"
                            type="number"
                            min="1"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label for="group-row-spacing">Row spacing</Label>
                        <Input
                            id="group-row-spacing"
                            v-model.number="form.rowSpacing"
                            type="number"
                            min="1"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div class="grid gap-1">
                        <Label for="group-start">Start index</Label>
                        <Input
                            id="group-start"
                            v-model.number="form.startIndex"
                            type="number"
                            min="0"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label for="group-row-schema">Row schema</Label>
                        <Input id="group-row-schema" v-model="form.rowSchema" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="group-place-schema">Place schema</Label>
                        <Input
                            id="group-place-schema"
                            v-model="form.placeSchema"
                        />
                    </div>
                </div>
                <p class="text-xs text-text-muted">
                    Placeholders: {row} (the group label), {n} and {n:02}
                    (running index), {col} (grid column).
                </p>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('update:open', false)"
                        >Cancel</Button
                    >
                    <Button type="submit" :disabled="categories.length === 0"
                        >Create group</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

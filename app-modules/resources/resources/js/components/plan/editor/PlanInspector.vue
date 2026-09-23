<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { elementKindLabel, elementKindPluralLabel } from '../labels';
import type { DraftElement } from './document';
import PlanInspectorKindFields from './PlanInspectorKindFields.vue';
import PlanInspectorReservation from './PlanInspectorReservation.vue';
import type { PlanStore } from './usePlanStore';

/**
 * Context-sensitive panel: the plan when nothing is selected, one
 * element's fields when one is, bulk actions when several are. Common
 * fields live here, kind-specific ones in `PlanInspectorKindFields`, the
 * operator reservation in `PlanInspectorReservation`.
 */
const props = defineProps<{
    store: PlanStore;
    planId: string;
    categories: Konventix.Resources.Data.CategoryData[];
    occupancy: Konventix.Resources.Data.PlanOccupancyData;
    canReserve: boolean;
}>();

const emit = defineEmits<{
    regenerate: [groupId: string];
    'occupancy-changed': [];
}>();

const store = props.store;

const selected = computed(() => store.selectedElements.value);
const single = computed(() =>
    selected.value.length === 1 ? selected.value[0] : null,
);
const selectedPlaces = computed(() =>
    selected.value.filter((element) => element.kind === 'place'),
);

const counts = computed(() => {
    const out: Record<string, number> = {};

    for (const element of store.document.value.elements) {
        out[element.kind] = (out[element.kind] ?? 0) + 1;
    }

    return out;
});

function patch(field: string, apply: (element: DraftElement) => void): void {
    if (single.value) {
        store.updateElement(single.value.id, `inspector:${field}`, apply);
    }
}

function patchNumber(
    field: 'x' | 'y' | 'rotation',
    value: string | number | null,
): void {
    const parsed = Number(value);

    if (!Number.isFinite(parsed)) {
        return;
    }

    patch(field, (element) => {
        element[field] = field === 'rotation' ? parsed : Math.round(parsed);
    });
}

function patchShape(
    field: 'w' | 'h' | 'rx' | 'ry' | 'radius',
    value: string | number | null,
): void {
    const parsed = Number(value);

    if (!Number.isFinite(parsed)) {
        return;
    }

    patch(`shape.${field}`, (element) => {
        element.shape[field] = Math.max(1, Math.round(parsed));
    });
}

function bulkCategory(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    const ids = new Set(selectedPlaces.value.map((element) => element.id));

    store.applyMutation(
        'bulk:category',
        (draft) => {
            for (const element of draft.elements) {
                if (ids.has(element.id)) {
                    element.resourceTypeId = value || null;
                }
            }
        },
        false,
    );
}

function bulkFlag(field: 'blocked' | 'locked', value: boolean): void {
    const ids = new Set(selected.value.map((element) => element.id));

    store.applyMutation(
        `bulk:${field}`,
        (draft) => {
            for (const element of draft.elements) {
                if (ids.has(element.id)) {
                    element[field] = value;
                }
            }
        },
        false,
    );
}
</script>

<template>
    <div class="flex flex-col gap-3 p-3">
        <template v-if="selected.length === 0">
            <h2 class="text-sm font-semibold text-text">{{ $t('Plan') }}</h2>
            <dl class="grid grid-cols-2 gap-x-2 gap-y-1 text-sm">
                <template
                    v-for="kind in [
                        'zone',
                        'group',
                        'place',
                        'fixture',
                        'annotation',
                    ]"
                    :key="kind"
                >
                    <dt class="text-text-muted">
                        {{ elementKindPluralLabel(kind) }}
                    </dt>
                    <dd class="text-text">{{ counts[kind] ?? 0 }}</dd>
                </template>
            </dl>
            <p class="text-xs text-text-muted">
                {{
                    $t(
                        'Click an element on the canvas or in the tree to edit it. Shift-click adds to the selection; drag on the floor to marquee.',
                    )
                }}
            </p>
        </template>

        <template v-else-if="single">
            <h2 class="text-sm font-semibold text-text">
                <span class="text-text-muted uppercase">{{
                    elementKindLabel(single.kind)
                }}</span>
                {{ single.label ?? single.ref ?? '' }}
            </h2>

            <div class="grid gap-1">
                <Label for="inspector-label">{{ $t('Label') }}</Label>
                <Input
                    id="inspector-label"
                    :model-value="single.label"
                    @update:model-value="
                        (value) =>
                            patch('label', (draft) => {
                                draft.label =
                                    value === null || value === ''
                                        ? null
                                        : String(value);
                            })
                    "
                />
            </div>
            <div v-if="single.kind === 'place'" class="grid gap-1">
                <Label for="inspector-ref">{{
                    $t('Ref (unique per plan)')
                }}</Label>
                <Input
                    id="inspector-ref"
                    :model-value="single.ref"
                    @update:model-value="
                        (value) =>
                            patch('ref', (draft) => {
                                draft.ref =
                                    value === null || value === ''
                                        ? null
                                        : String(value);
                            })
                    "
                />
            </div>

            <div class="grid grid-cols-3 gap-2">
                <div class="grid gap-1">
                    <Label for="inspector-x">{{ $t('X (mm)') }}</Label>
                    <Input
                        id="inspector-x"
                        type="number"
                        :model-value="single.x"
                        @update:model-value="(value) => patchNumber('x', value)"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="inspector-y">{{ $t('Y (mm)') }}</Label>
                    <Input
                        id="inspector-y"
                        type="number"
                        :model-value="single.y"
                        @update:model-value="(value) => patchNumber('y', value)"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="inspector-rotation">{{
                        $t('Rotation (°)')
                    }}</Label>
                    <Input
                        id="inspector-rotation"
                        type="number"
                        step="0.5"
                        :model-value="single.rotation"
                        @update:model-value="
                            (value) => patchNumber('rotation', value)
                        "
                    />
                </div>
            </div>

            <div
                v-if="single.shape.type === 'rect'"
                class="grid grid-cols-2 gap-2"
            >
                <div class="grid gap-1">
                    <Label for="inspector-w">{{ $t('Width (mm)') }}</Label>
                    <Input
                        id="inspector-w"
                        type="number"
                        min="1"
                        :model-value="single.shape.w"
                        @update:model-value="(value) => patchShape('w', value)"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="inspector-h">{{ $t('Height (mm)') }}</Label>
                    <Input
                        id="inspector-h"
                        type="number"
                        min="1"
                        :model-value="single.shape.h"
                        @update:model-value="(value) => patchShape('h', value)"
                    />
                </div>
            </div>
            <div
                v-else-if="single.shape.type === 'ellipse'"
                class="grid grid-cols-2 gap-2"
            >
                <div class="grid gap-1">
                    <Label for="inspector-rx">{{ $t('Radius X (mm)') }}</Label>
                    <Input
                        id="inspector-rx"
                        type="number"
                        min="1"
                        :model-value="single.shape.rx"
                        @update:model-value="(value) => patchShape('rx', value)"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="inspector-ry">{{ $t('Radius Y (mm)') }}</Label>
                    <Input
                        id="inspector-ry"
                        type="number"
                        min="1"
                        :model-value="single.shape.ry"
                        @update:model-value="(value) => patchShape('ry', value)"
                    />
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm text-text">
                <Checkbox
                    :model-value="single.locked"
                    @update:model-value="
                        (value) =>
                            patch('locked', (draft) => {
                                draft.locked = value === true;
                            })
                    "
                />
                {{ $t('Locked (cannot be dragged)') }}
            </label>

            <PlanInspectorKindFields
                :store="store"
                :element="single"
                :categories="categories"
                @regenerate="(id) => emit('regenerate', id)"
            />

            <PlanInspectorReservation
                v-if="single.kind === 'place'"
                :plan-id="planId"
                :place-id="single.id"
                :entry="occupancy.places[single.id] ?? null"
                :can-reserve="canReserve"
                :dirty="store.isDirty.value"
                @changed="emit('occupancy-changed')"
            />

            <Button
                type="button"
                variant="outline"
                size="sm"
                class="text-danger"
                @click="store.removeElements([single.id])"
            >
                <Trash2 class="size-4" />
                {{ $t('Delete') }}
            </Button>
        </template>

        <template v-else>
            <h2 class="text-sm font-semibold text-text">
                {{
                    $t(':count elements selected', {
                        count: String(selected.length),
                    })
                }}
            </h2>
            <div v-if="selectedPlaces.length > 0" class="grid gap-1">
                <Label for="inspector-bulk-category">{{
                    $tChoice(
                        '{1} Category for :count place|[2,*] Category for :count places',
                        selectedPlaces.length,
                    )
                }}</Label>
                <select
                    id="inspector-bulk-category"
                    value=""
                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm text-text"
                    @change="bulkCategory"
                >
                    <option value="" disabled>{{ $t('Choose…') }}</option>
                    <option
                        v-for="category in categories"
                        :key="category.id"
                        :value="category.id"
                    >
                        {{ category.name }}
                    </option>
                </select>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="bulkFlag('blocked', true)"
                    >{{ $t('Block') }}</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="bulkFlag('blocked', false)"
                    >{{ $t('Unblock') }}</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="bulkFlag('locked', true)"
                    >{{ $t('Lock') }}</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="bulkFlag('locked', false)"
                    >{{ $t('Unlock') }}</Button
                >
            </div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="text-danger"
                @click="store.removeElements(store.selection.value)"
            >
                <Trash2 class="size-4" />
                {{ $t('Delete selection') }}
            </Button>
        </template>
    </div>
</template>

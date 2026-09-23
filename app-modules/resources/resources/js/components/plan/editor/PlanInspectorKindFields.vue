<script setup lang="ts">
import { RefreshCw } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fixtureTypeLabel } from '../labels';
import { tokenVar } from '../tokens';
import type { DraftElement, PlanElement } from './document';
import type { PlanStore } from './usePlanStore';

/**
 * The fields that exist for one kind only (design §5): zone token, group
 * layout and label schema, place category and flags, fixture type,
 * annotation text. Every change is one labelled mutation the store
 * coalesces while the operator types.
 */
const props = defineProps<{
    store: PlanStore;
    element: PlanElement;
    categories: Konventix.Resources.Data.CategoryData[];
}>();

const emit = defineEmits<{ regenerate: [groupId: string] }>();

const store = props.store;
const TOKENS: Konventix.Resources.Enums.CategoryToken[] = [
    'chart-1',
    'chart-2',
    'chart-3',
    'chart-4',
    'chart-5',
    'accent',
    'success',
    'warning',
    'danger',
];
const FIXTURES: Konventix.Resources.Enums.FixtureType[] = [
    'table',
    'counter',
    'stage',
    'wall',
    'pillar',
    'custom',
];

function patch(field: string, apply: (element: DraftElement) => void): void {
    store.updateElement(props.element.id, `inspector:${field}`, apply);
}

function number(value: string | number | null): number | null {
    if (value === null || value === '') {
        return null;
    }

    const parsed = Number(value);

    return Number.isFinite(parsed) ? Math.round(parsed) : null;
}

function patchLayout(
    field: 'count' | 'rows' | 'cols' | 'spacing' | 'rowSpacing' | 'startIndex',
    value: string | number | null,
): void {
    patch(`layout.${field}`, (element) => {
        if (!element.layout) {
            return;
        }

        // A generic key write through a union of six fields cannot see that
        // `spacing`/`startIndex` never actually receive `null` (the ternary
        // below guarantees it); the cast reflects that runtime guarantee to
        // the compiler without loosening every other write in this file.
        const next =
            number(value) ??
            (field === 'spacing' || field === 'startIndex' ? 1 : null);
        (element.layout as unknown as Record<string, number | null>)[field] =
            next;
    });
}

function setLayoutType(event: Event): void {
    const type = (event.target as HTMLSelectElement)
        .value as Konventix.Resources.Enums.LayoutKind;

    patch('layout.type', (element) => {
        element.layout = element.layout
            ? { ...element.layout, type }
            : {
                  type,
                  count: 8,
                  rows: 2,
                  cols: 4,
                  spacing: 800,
                  rowSpacing: 1000,
                  startIndex: 1,
              };
    });
}

function patchSchema(
    field: 'row' | 'place',
    value: string | number | null,
): void {
    patch(`labelSchema.${field}`, (element) => {
        element.labelSchema = {
            row: '{row}',
            place: '{row}-{n:02}',
            ...element.labelSchema,
            [field]: String(value ?? ''),
        };
    });
}

function onCategory(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;

    patch('resourceTypeId', (element) => {
        element.resourceTypeId = value === '' ? null : value;
    });
}

function onFlag(
    field: 'accessible' | 'blocked' | 'detached',
    value: boolean | 'indeterminate',
): void {
    patch(field, (element) => {
        element[field] = value === true;
    });
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <template v-if="element.kind === 'zone' || element.kind === 'fixture'">
            <div class="grid gap-1">
                <Label for="inspector-token">{{ $t('Fill token') }}</Label>
                <select
                    id="inspector-token"
                    :value="element.token ?? ''"
                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm text-text"
                    @change="
                        (event) =>
                            patch('token', (draft) => {
                                draft.token = ((
                                    event.target as HTMLSelectElement
                                ).value || null) as DraftElement['token'];
                            })
                    "
                >
                    <option value="">{{ $t('None') }}</option>
                    <option v-for="token in TOKENS" :key="token" :value="token">
                        {{ token }}
                    </option>
                </select>
                <span
                    v-if="element.token"
                    class="inline-block h-3 w-8 rounded"
                    :style="{ background: tokenVar(element.token) }"
                    aria-hidden="true"
                />
            </div>
        </template>

        <template v-if="element.kind === 'fixture'">
            <div class="grid gap-1">
                <Label for="inspector-fixture">{{ $t('Fixture') }}</Label>
                <select
                    id="inspector-fixture"
                    :value="element.fixtureType ?? 'custom'"
                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm text-text"
                    @change="
                        (event) =>
                            patch('fixtureType', (draft) => {
                                draft.fixtureType = (
                                    event.target as HTMLSelectElement
                                ).value as DraftElement['fixtureType'];
                            })
                    "
                >
                    <option
                        v-for="fixture in FIXTURES"
                        :key="fixture"
                        :value="fixture"
                    >
                        {{ fixtureTypeLabel(fixture) }}
                    </option>
                </select>
            </div>
        </template>

        <template v-if="element.kind === 'group'">
            <div class="grid gap-1">
                <Label for="inspector-layout">{{ $t('Layout') }}</Label>
                <select
                    id="inspector-layout"
                    :value="element.layout?.type ?? ''"
                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm text-text"
                    @change="setLayoutType"
                >
                    <option value="" disabled>{{ $t('Choose…') }}</option>
                    <option value="linear">{{ $t('Linear') }}</option>
                    <option value="grid">{{ $t('Grid') }}</option>
                </select>
            </div>
            <template v-if="element.layout">
                <div v-if="element.layout.type === 'linear'" class="grid gap-1">
                    <Label for="inspector-count">{{ $t('Count') }}</Label>
                    <Input
                        id="inspector-count"
                        type="number"
                        min="0"
                        :model-value="element.layout.count"
                        @update:model-value="
                            (value) => patchLayout('count', value)
                        "
                    />
                </div>
                <div v-else class="grid grid-cols-2 gap-2">
                    <div class="grid gap-1">
                        <Label for="inspector-rows">{{ $t('Rows') }}</Label>
                        <Input
                            id="inspector-rows"
                            type="number"
                            min="0"
                            :model-value="element.layout.rows"
                            @update:model-value="
                                (value) => patchLayout('rows', value)
                            "
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label for="inspector-cols">{{ $t('Columns') }}</Label>
                        <Input
                            id="inspector-cols"
                            type="number"
                            min="0"
                            :model-value="element.layout.cols"
                            @update:model-value="
                                (value) => patchLayout('cols', value)
                            "
                        />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div class="grid gap-1">
                        <Label for="inspector-spacing">{{
                            $t('Spacing (mm)')
                        }}</Label>
                        <Input
                            id="inspector-spacing"
                            type="number"
                            min="1"
                            :model-value="element.layout.spacing"
                            @update:model-value="
                                (value) => patchLayout('spacing', value)
                            "
                        />
                    </div>
                    <div
                        v-if="element.layout.type === 'grid'"
                        class="grid gap-1"
                    >
                        <Label for="inspector-row-spacing">{{
                            $t('Row spacing (mm)')
                        }}</Label>
                        <Input
                            id="inspector-row-spacing"
                            type="number"
                            min="1"
                            :model-value="element.layout.rowSpacing"
                            @update:model-value="
                                (value) => patchLayout('rowSpacing', value)
                            "
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label for="inspector-start">{{
                            $t('Start index')
                        }}</Label>
                        <Input
                            id="inspector-start"
                            type="number"
                            min="0"
                            :model-value="element.layout.startIndex"
                            @update:model-value="
                                (value) => patchLayout('startIndex', value)
                            "
                        />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div class="grid gap-1">
                        <Label for="inspector-schema-row">{{
                            $t('Row schema')
                        }}</Label>
                        <Input
                            id="inspector-schema-row"
                            :model-value="element.labelSchema?.row ?? '{row}'"
                            @update:model-value="
                                (value) => patchSchema('row', value)
                            "
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label for="inspector-schema-place">{{
                            $t('Place schema')
                        }}</Label>
                        <Input
                            id="inspector-schema-place"
                            :model-value="
                                element.labelSchema?.place ?? '{row}-{n:02}'
                            "
                            @update:model-value="
                                (value) => patchSchema('place', value)
                            "
                        />
                    </div>
                </div>
                <p class="text-xs text-text-muted">
                    {{ $t('Placeholders:') }} {row}, {n}, {n:02}, {col}.
                    {{ $t('Regenerating keeps ids and hand-moved places.') }}
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="emit('regenerate', element.id)"
                >
                    <RefreshCw class="size-4" />
                    {{ $t('Regenerate places') }}
                </Button>
            </template>
        </template>

        <template v-if="element.kind === 'place'">
            <div class="grid gap-1">
                <Label for="inspector-category">{{ $t('Category') }}</Label>
                <select
                    id="inspector-category"
                    :value="element.resourceTypeId ?? ''"
                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm text-text"
                    :aria-invalid="element.resourceTypeId === null"
                    @change="onCategory"
                >
                    <option value="">{{ $t('— none —') }}</option>
                    <option
                        v-for="category in categories"
                        :key="category.id"
                        :value="category.id"
                    >
                        {{ category.name }}
                    </option>
                </select>
            </div>
            <label class="flex items-center gap-2 text-sm text-text">
                <Checkbox
                    :model-value="element.blocked"
                    @update:model-value="(value) => onFlag('blocked', value)"
                />
                {{ $t('Blocked (nobody can pick it)') }}
            </label>
            <label class="flex items-center gap-2 text-sm text-text">
                <Checkbox
                    :model-value="element.accessible"
                    @update:model-value="(value) => onFlag('accessible', value)"
                />
                {{ $t('Accessible place') }}
            </label>
            <label class="flex items-center gap-2 text-sm text-text">
                <Checkbox
                    :model-value="element.detached"
                    @update:model-value="(value) => onFlag('detached', value)"
                />
                {{ $t('Detached from the generator') }}
            </label>
        </template>

        <template v-if="element.kind === 'annotation'">
            <div class="grid gap-1">
                <Label for="inspector-text">{{ $t('Text') }}</Label>
                <Input
                    id="inspector-text"
                    :model-value="element.text"
                    @update:model-value="
                        (value) =>
                            patch('text', (draft) => {
                                draft.text = String(value ?? '');
                            })
                    "
                />
            </div>
            <div class="grid gap-1">
                <Label for="inspector-font">{{ $t('Font size (mm)') }}</Label>
                <Input
                    id="inspector-font"
                    type="number"
                    min="50"
                    :model-value="element.fontSizeMm"
                    @update:model-value="
                        (value) =>
                            patch('fontSizeMm', (draft) => {
                                draft.fontSizeMm = number(value);
                            })
                    "
                />
            </div>
        </template>
    </div>
</template>

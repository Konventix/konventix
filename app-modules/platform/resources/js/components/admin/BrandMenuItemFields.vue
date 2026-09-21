<script setup lang="ts">
import { GripVertical, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

/**
 * One entry of the brand menu form: its kind, its destination and where it
 * sits. Emits a replaced entry rather than mutating the one it was given,
 * so the parent form stays the owner of its list (and of `isDirty`).
 */
type MenuItem = Konventix.Platform.Data.BrandMenuItemData;
type PageOption = Konventix.Platform.Data.BrandMenuPageOptionData;
type Kind = Konventix.Platform.Enums.BrandMenuItemKind;

const props = defineProps<{
    modelValue: MenuItem;
    index: number;
    count: number;
    hintId: string;
    pages: PageOption[];
    parentOptions: { key: string; label: string }[];
    errors: Record<string, string | undefined>;
    dragging: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: MenuItem];
    remove: [];
    dragstart: [event: DragEvent];
    dragenter: [];
    drop: [];
    dragend: [];
    'handle-keydown': [event: KeyboardEvent];
}>();

/** The `parent select`'s "top level" option — reka refuses an empty value. */
const NO_PARENT = '__none__';

const id = (field: string): string => `${props.hintId}-${field}-${props.index}`;

function patch(changes: Partial<MenuItem>): void {
    emit('update:modelValue', { ...props.modelValue, ...changes });
}

const page = computed(() =>
    props.pages.find((candidate) => candidate.id === props.modelValue.pageId),
);

const visibilityLabel: Record<PageOption['visibility'], string> = {
    public: 'Everyone',
    members: 'Members only',
    registrants: 'Registrants only',
};

const displayName = computed(
    () =>
        props.modelValue.label ||
        page.value?.title ||
        `entry ${props.index + 1}`,
);
</script>

<template>
    <fieldset
        draggable="true"
        :class="[
            'flex flex-col gap-4 rounded-lg border border-border p-4',
            props.dragging && 'opacity-60',
        ]"
        @dragstart="emit('dragstart', $event)"
        @dragenter.prevent="emit('dragenter')"
        @dragover.prevent
        @drop.prevent="emit('drop')"
        @dragend="emit('dragend')"
    >
        <legend class="flex items-center gap-2 px-1 text-sm font-medium">
            <button
                type="button"
                class="cursor-grab rounded p-1 text-text-muted hover:text-text active:cursor-grabbing"
                :aria-describedby="props.hintId"
                @keydown="emit('handle-keydown', $event)"
            >
                <GripVertical class="size-4" />
                <span class="sr-only">
                    Reorder {{ displayName }}, position {{ props.index + 1 }} of
                    {{ props.count }}
                </span>
            </button>
            Entry {{ props.index + 1 }}
        </legend>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label :for="id('kind')">Type</Label>
                <Select
                    :model-value="props.modelValue.kind"
                    @update:model-value="
                        (value) => patch({ kind: value as Kind })
                    "
                >
                    <SelectTrigger :id="id('kind')">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="page">Page</SelectItem>
                        <SelectItem value="url">Link</SelectItem>
                        <SelectItem value="parent">Group</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div class="grid gap-2">
                <Label :for="id('label')">Label</Label>
                <Input
                    :id="id('label')"
                    :model-value="props.modelValue.label ?? ''"
                    :placeholder="
                        props.modelValue.kind === 'page'
                            ? page?.title || 'Page title'
                            : 'Label'
                    "
                    @update:model-value="
                        (value) =>
                            patch({
                                label:
                                    value === null || value === ''
                                        ? null
                                        : String(value),
                            })
                    "
                />
                <InputError :message="props.errors.label" />
            </div>
        </div>

        <div v-if="props.modelValue.kind === 'page'" class="grid gap-2">
            <Label :for="id('page')">Page</Label>
            <Select
                :model-value="props.modelValue.pageId ?? undefined"
                @update:model-value="
                    (value) => patch({ pageId: String(value) })
                "
            >
                <SelectTrigger :id="id('page')">
                    <SelectValue placeholder="Pick a page" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="candidate in props.pages"
                        :key="candidate.id"
                        :value="candidate.id"
                    >
                        {{ candidate.title }}
                        <span class="text-text-muted">
                            /{{ candidate.slug }}
                        </span>
                    </SelectItem>
                </SelectContent>
            </Select>
            <div v-if="page" class="flex flex-wrap items-center gap-2">
                <Badge variant="outline">
                    {{ visibilityLabel[page.visibility] }}
                </Badge>
                <Badge v-if="!page.published" variant="secondary">
                    Draft — hidden until published
                </Badge>
                <span class="text-xs text-text-muted">
                    Inherited from the page.
                </span>
            </div>
            <InputError :message="props.errors.pageId" />
        </div>

        <div v-else-if="props.modelValue.kind === 'url'" class="grid gap-2">
            <Label :for="id('url')">Destination</Label>
            <Input
                :id="id('url')"
                :model-value="props.modelValue.url ?? ''"
                placeholder="/shop or https://…"
                @update:model-value="
                    (value) =>
                        patch({
                            url:
                                value === null || value === ''
                                    ? null
                                    : String(value),
                        })
                "
            />
            <p class="text-xs text-text-muted">
                A path on this site, an https:// address, or a mailto: address.
            </p>
            <InputError :message="props.errors.url" />
            <div class="flex items-center gap-2">
                <Checkbox
                    :id="id('newtab')"
                    :model-value="props.modelValue.opensInNewTab"
                    @update:model-value="
                        (value: boolean | 'indeterminate') =>
                            patch({ opensInNewTab: value === true })
                    "
                />
                <Label :for="id('newtab')" class="font-normal">
                    Open in a new tab
                </Label>
            </div>
        </div>

        <p v-else class="text-xs text-text-muted">
            A group navigates nowhere itself; place entries under it with the
            "Under" field.
        </p>

        <div
            v-if="props.modelValue.kind !== 'parent'"
            class="grid gap-2 sm:max-w-xs"
        >
            <Label :for="id('parent')">Under</Label>
            <Select
                :model-value="props.modelValue.parentKey ?? NO_PARENT"
                @update:model-value="
                    (value) =>
                        patch({
                            parentKey:
                                value === NO_PARENT ? null : String(value),
                        })
                "
            >
                <SelectTrigger :id="id('parent')">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="NO_PARENT">Top level</SelectItem>
                    <SelectItem
                        v-for="option in props.parentOptions"
                        :key="option.key"
                        :value="option.key"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <InputError :message="props.errors.parentKey" />
        </div>

        <div class="flex items-center gap-2">
            <Button
                type="button"
                variant="ghost"
                size="icon"
                :aria-label="`Remove ${displayName}`"
                @click="emit('remove')"
            >
                <Trash2 class="size-4" />
            </Button>
        </div>
    </fieldset>
</template>

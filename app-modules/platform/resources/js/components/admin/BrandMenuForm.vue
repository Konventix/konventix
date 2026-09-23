<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useDragReorder } from '@/composables/useDragReorder';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { update as updateMenu } from '@/routes/admin/menu';
import BrandMenuItemFields from './BrandMenuItemFields.vue';

/**
 * One brand's menu, edited and saved as a whole (the footer form's shape).
 * Position is the array index among siblings, so dragging an entry into
 * place is the same submit as relabelling it. Hierarchy is a `parentKey`
 * per entry pointing at a group's `key`: keys are made up here and mapped
 * onto fresh rows by the server, so a new entry gets a client-side id and
 * an existing one keeps its row id.
 */
type MenuItem = Konventix.Platform.Data.BrandMenuItemData;
type PageOption = Konventix.Platform.Data.BrandMenuPageOptionData;

const props = defineProps<{
    brandId: string;
    brandName: string;
    items: MenuItem[];
    pages: PageOption[];
}>();

const form = useForm<{ brandId: string; items: MenuItem[] }>({
    brandId: props.brandId,
    items: props.items.map((item) => ({ ...item })),
});

useUnsavedChanges(() => form.isDirty);

const hintId = computed(() => `menu-reorder-hint-${props.brandId}`);

const {
    draggingIndex,
    onDragStart,
    onDragEnter,
    onDrop,
    onDragEnd,
    onHandleKeydown,
} = useDragReorder<MenuItem>({
    get: () => form.items,
    set: (next) => (form.items = next),
});

/** The groups an entry may sit under: every `parent` entry but itself. */
function parentOptionsFor(item: MenuItem): { key: string; label: string }[] {
    return form.items
        .filter((candidate) => candidate.kind === 'parent')
        .filter((candidate) => candidate.key !== item.key)
        .map((candidate) => ({
            key: candidate.key,
            label: candidate.label || trans('Unnamed group'),
        }));
}

function newKey(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `new-${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
}

function addItem(): void {
    form.items.push({
        key: newKey(),
        kind: 'page',
        label: null,
        pageId: null,
        url: null,
        parentKey: null,
        opensInNewTab: false,
    });
}

/**
 * Replaces one entry. A group cannot sit under a group, and an entry that
 * stops being a group can no longer hold children — both fixed up here so
 * the server's refusal is never the first the operator hears of it.
 */
function replace(index: number, next: MenuItem): void {
    const wasParent = form.items[index].kind === 'parent';

    if (next.kind === 'parent') {
        next = { ...next, parentKey: null };
    }

    form.items[index] = next;

    if (wasParent && next.kind !== 'parent') {
        form.items = form.items.map((item) =>
            item.parentKey === next.key ? { ...item, parentKey: null } : item,
        );
    }
}

function remove(index: number): void {
    const removed = form.items[index];

    form.items = form.items
        .filter((_, candidate) => candidate !== index)
        .map((item) =>
            item.parentKey === removed.key
                ? { ...item, parentKey: null }
                : item,
        );
}

function errorsFor(index: number): Record<string, string | undefined> {
    const prefix = `items.${index}.`;

    return Object.fromEntries(
        Object.entries(form.errors as Record<string, string>)
            .filter(([field]) => field.startsWith(prefix))
            .map(([field, message]) => [field.slice(prefix.length), message]),
    );
}

function submit(): void {
    form.put(updateMenu().url, { preserveScroll: true });
}
</script>

<template>
    <Card>
        <CardContent class="pt-6">
            <form class="flex flex-col gap-6" @submit.prevent="submit">
                <div class="flex flex-col gap-1">
                    <h2 class="text-base font-medium">
                        {{ props.brandName }}
                    </h2>
                    <p class="text-sm text-text-muted">
                        {{
                            $t(
                                'Shown in the topbar after the module entries, in this order.',
                            )
                        }}
                    </p>
                </div>

                <p :id="hintId" class="sr-only">
                    {{
                        $t(
                            'Drag an entry to reorder it, or focus its reorder handle and press the up or down arrow key.',
                        )
                    }}
                </p>

                <p
                    v-if="form.items.length === 0"
                    class="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-text-muted"
                >
                    {{ $t('No entries yet.') }}
                </p>

                <BrandMenuItemFields
                    v-for="(item, index) in form.items"
                    :key="item.key"
                    :model-value="item"
                    :index="index"
                    :count="form.items.length"
                    :hint-id="hintId"
                    :pages="props.pages"
                    :parent-options="parentOptionsFor(item)"
                    :errors="errorsFor(index)"
                    :dragging="draggingIndex === index"
                    @update:model-value="(next) => replace(index, next)"
                    @remove="remove(index)"
                    @dragstart="onDragStart(index, $event)"
                    @dragenter="onDragEnter(index)"
                    @drop="onDrop"
                    @dragend="onDragEnd"
                    @handle-keydown="onHandleKeydown(index, $event)"
                />

                <InputError :message="form.errors.items" />

                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    class="self-start"
                    @click="addItem"
                >
                    {{ $t('Add entry') }}
                </Button>

                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing">
                        {{ $t('Save') }}
                    </Button>
                    <span
                        v-if="form.isDirty"
                        role="status"
                        class="text-sm text-warning"
                    >
                        {{ $t('Unsaved changes') }}
                    </span>
                </div>
            </form>
        </CardContent>
    </Card>
</template>

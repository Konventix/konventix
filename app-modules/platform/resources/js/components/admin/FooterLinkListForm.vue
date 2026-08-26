<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { GripVertical, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import { useDragReorder } from '@/composables/useDragReorder';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { update as updateFooter } from '@/routes/admin/settings/footer';

/**
 * One footer list — operator-wide, or one brand's own — edited and saved as
 * a whole. Position is the array index, so dragging an entry into place is
 * the same submit as editing a label; there is no separate reorder endpoint
 * to drift out of sync with this one.
 */
type FooterLink = Konventix.Platform.Data.FooterLinkData;

const props = defineProps<{
    links: FooterLink[];
    brandId: string | null;
    title: string;
    description: string;
}>();

const form = useForm<{ brandId: string | null; links: FooterLink[] }>({
    brandId: props.brandId,
    links: props.links.map((link) => ({ ...link })),
});

useUnsavedChanges(() => form.isDirty);

// A unique id per instance so the sr-only hint each list needs doesn't
// collide when both the operator-wide and a brand's form render together.
const hintId = computed(
    () => `footer-reorder-hint-${props.brandId ?? 'operator'}`,
);

const {
    draggingIndex,
    onDragStart,
    onDragEnter,
    onDrop,
    onDragEnd,
    onHandleKeydown,
} = useDragReorder<FooterLink>({
    get: () => form.links,
    set: (next) => (form.links = next),
});

function addLink(): void {
    form.links.push({
        label: '',
        kind: 'url',
        url: '',
        dialogKey: null,
        opensInNewTab: false,
    });
}

function submit(): void {
    form.put(updateFooter().url, { preserveScroll: true });
}
</script>

<template>
    <Card>
        <CardContent class="pt-6">
            <form class="flex flex-col gap-6" @submit.prevent="submit">
                <div class="flex flex-col gap-1">
                    <h2 class="text-base font-medium">{{ props.title }}</h2>
                    <p class="text-sm text-text-muted">
                        {{ props.description }}
                    </p>
                </div>

                <p :id="hintId" class="sr-only">
                    Drag an entry to reorder it, or focus its reorder handle and
                    press the up or down arrow key.
                </p>

                <p
                    v-if="form.links.length === 0"
                    class="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-text-muted"
                >
                    No entries yet.
                </p>

                <fieldset
                    v-for="(link, index) in form.links"
                    :key="index"
                    draggable="true"
                    :class="[
                        'flex flex-col gap-4 rounded-lg border border-border p-4',
                        draggingIndex === index && 'opacity-60',
                    ]"
                    @dragstart="onDragStart(index, $event)"
                    @dragenter.prevent="onDragEnter(index)"
                    @dragover.prevent
                    @drop.prevent="onDrop"
                    @dragend="onDragEnd"
                >
                    <legend
                        class="flex items-center gap-2 px-1 text-sm font-medium"
                    >
                        <button
                            type="button"
                            class="cursor-grab rounded p-1 text-text-muted hover:text-text active:cursor-grabbing"
                            :aria-describedby="hintId"
                            @keydown="onHandleKeydown(index, $event)"
                        >
                            <GripVertical class="size-4" />
                            <span class="sr-only">
                                Reorder
                                {{ link.label || `entry ${index + 1}` }},
                                position {{ index + 1 }} of
                                {{ form.links.length }}
                            </span>
                        </button>
                        Entry {{ index + 1 }}
                    </legend>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label :for="`${hintId}-label-${index}`">
                                Label
                            </Label>
                            <Input
                                :id="`${hintId}-label-${index}`"
                                v-model="link.label"
                                placeholder="Imprint"
                            />
                            <InputError
                                :message="form.errors[`links.${index}.label`]"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label :for="`${hintId}-kind-${index}`">
                                Type
                            </Label>
                            <Select v-model="link.kind">
                                <SelectTrigger :id="`${hintId}-kind-${index}`">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="url">
                                        Page link
                                    </SelectItem>
                                    <SelectItem value="dialog">
                                        In-page dialog
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div v-if="link.kind === 'url'" class="grid gap-2">
                        <Label :for="`${hintId}-url-${index}`">
                            Destination
                        </Label>
                        <Input
                            :id="`${hintId}-url-${index}`"
                            v-model="link.url"
                            placeholder="/pages/imprint"
                        />
                        <p class="text-xs text-text-muted">
                            A path on this site, an https:// address, or a
                            mailto: address.
                        </p>
                        <InputError
                            :message="form.errors[`links.${index}.url`]"
                        />
                        <div class="flex items-center gap-2">
                            <Checkbox
                                :id="`${hintId}-newtab-${index}`"
                                :model-value="link.opensInNewTab"
                                @update:model-value="
                                    (value: boolean | 'indeterminate') =>
                                        (link.opensInNewTab = value === true)
                                "
                            />
                            <Label
                                :for="`${hintId}-newtab-${index}`"
                                class="font-normal"
                            >
                                Open in a new tab
                            </Label>
                        </div>
                    </div>

                    <div v-else class="grid gap-2">
                        <Label :for="`${hintId}-dialog-${index}`">
                            Dialog key
                        </Label>
                        <Input
                            :id="`${hintId}-dialog-${index}`"
                            :model-value="link.dialogKey ?? ''"
                            placeholder="cookie-settings"
                            @update:model-value="
                                (value: string | number | null) =>
                                    (link.dialogKey =
                                        value === null ? null : String(value))
                            "
                        />
                        <InputError
                            :message="form.errors[`links.${index}.dialogKey`]"
                        />
                    </div>

                    <div class="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :aria-label="`Remove ${link.label || `entry ${index + 1}`}`"
                            @click="form.links.splice(index, 1)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </fieldset>

                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    class="self-start"
                    @click="addLink"
                >
                    Add entry
                </Button>

                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing">
                        Save
                    </Button>
                    <span
                        v-if="form.isDirty"
                        role="status"
                        class="text-sm text-warning"
                    >
                        Unsaved changes
                    </span>
                </div>
            </form>
        </CardContent>
    </Card>
</template>

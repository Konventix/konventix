<script setup lang="ts">
import { ImageOff, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Picks one image out of a brand's media library (ADR-0035) and yields its
 * ULID — consumers store the ULID, never a path, which is the library's
 * whole contract.
 *
 * The candidate list is supplied by the page rather than fetched here: the
 * controller already knows which brand is being edited, and a component that
 * fetched for itself would need to learn about brand scoping.
 */
type Props = {
    /** Field label, e.g. "Logo (light)". */
    label: string;
    /** The chosen media ULID, or null for "use the bundled fallback". */
    modelValue: string | null;
    /** Candidates, newest first, already narrowed to this brand's images. */
    media: Konventix.Platform.Data.MediaItemData[];
    describedBy?: string;
};

const props = defineProps<Props>();
const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();

const open = ref(false);
const search = ref('');

const selected = computed(
    () => props.media.find((item) => item.id === props.modelValue) ?? null,
);

const matches = computed(() => {
    const term = search.value.trim().toLowerCase();

    return term === ''
        ? props.media
        : props.media.filter((item) =>
              item.originalFilename.toLowerCase().includes(term),
          );
});

function choose(id: string): void {
    emit('update:modelValue', id);
    open.value = false;
}
</script>

<template>
    <div class="grid gap-2">
        <Label>{{ label }}</Label>
        <div class="flex items-center gap-3">
            <span
                class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-md border border-border bg-surface-muted"
            >
                <img
                    v-if="selected?.thumbUrl ?? selected?.url"
                    :src="selected?.thumbUrl ?? selected?.url ?? undefined"
                    :alt="selected?.altText ?? selected?.originalFilename"
                    class="size-full object-contain"
                />
                <ImageOff v-else class="size-5 text-text-muted" />
            </span>
            <div class="flex flex-col items-start gap-1">
                <p class="text-sm text-text-muted">
                    {{ selected?.originalFilename ?? 'Bundled default' }}
                </p>
                <div class="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :aria-describedby="describedBy"
                        @click="open = true"
                    >
                        Choose
                    </Button>
                    <Button
                        v-if="modelValue"
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="emit('update:modelValue', null)"
                    >
                        <X class="size-4" />
                        Clear
                    </Button>
                </div>
            </div>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{{ label }}</DialogTitle>
                    <DialogDescription>
                        Images uploaded to this brand's media library. Upload
                        new files under Media.
                    </DialogDescription>
                </DialogHeader>

                <Input
                    v-model="search"
                    type="search"
                    placeholder="Filter by filename"
                    :aria-label="`Filter ${label} candidates`"
                />

                <p
                    v-if="matches.length === 0"
                    class="py-8 text-center text-sm text-text-muted"
                >
                    Nothing to choose from yet.
                </p>
                <ul
                    v-else
                    class="grid max-h-96 grid-cols-2 gap-3 overflow-y-auto sm:grid-cols-4"
                >
                    <li v-for="item in matches" :key="item.id">
                        <button
                            type="button"
                            class="flex w-full flex-col items-center gap-1 rounded-md border p-2 text-center transition hover:bg-subtle"
                            :class="
                                item.id === modelValue
                                    ? 'border-accent'
                                    : 'border-border'
                            "
                            :aria-pressed="item.id === modelValue"
                            @click="choose(item.id)"
                        >
                            <img
                                v-if="item.thumbUrl ?? item.url"
                                :src="item.thumbUrl ?? item.url ?? undefined"
                                :alt="item.altText ?? item.originalFilename"
                                class="size-20 object-contain"
                            />
                            <span class="w-full truncate text-xs">
                                {{ item.originalFilename }}
                            </span>
                        </button>
                    </li>
                </ul>
            </DialogContent>
        </Dialog>
    </div>
</template>

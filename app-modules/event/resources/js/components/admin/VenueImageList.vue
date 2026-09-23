<script setup lang="ts">
import { ChevronDown, ChevronUp } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, useId } from 'vue';
import MediaPicker from '@/components/admin/MediaPicker.vue';
import { Button } from '@/components/ui/button';
import { moveImage, resolvePrimary, setImageAt } from '../../lib/venueForm';

/**
 * A venue's pictures: an ordered list of media-library ULIDs (ADR-0035)
 * with one marked primary. One `MediaPicker` per slot, plus one empty slot
 * at the end that adds a picture by being filled — so there is no "add"
 * button whose only job is to create a slot to fill.
 *
 * Order is the operator's and is what the public carousel shows, primary
 * first. Clearing a slot removes it; the primary follows the same rule the
 * `Venue` model enforces on save, so the form never displays a primary the
 * server would quietly move.
 */
const ids = defineModel<string[]>('ids', { required: true });
const primary = defineModel<string | null>('primary', { required: true });

const props = defineProps<{
    media: Konventix.Platform.Data.MediaItemData[];
    brandId: string;
    canUpload: boolean;
}>();

const uid = useId();

/** The filled slots plus one empty one to fill. */
const slots = computed<(string | null)[]>(() => [...ids.value, null]);

function choose(index: number, mediaId: string | null): void {
    const next = setImageAt(ids.value, index, mediaId);

    ids.value = next;
    primary.value = resolvePrimary(next, primary.value);
}

function move(index: number, offset: number): void {
    ids.value = moveImage(ids.value, index, offset);
}

function labelFor(mediaId: string | null): string {
    const item = props.media.find((candidate) => candidate.id === mediaId);

    return item?.altText ?? item?.originalFilename ?? trans('Picture');
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div
            v-for="(mediaId, index) in slots"
            :key="mediaId ?? 'empty-slot'"
            class="flex flex-wrap items-end gap-4 rounded-md border border-border p-3"
        >
            <div class="min-w-64 flex-1">
                <MediaPicker
                    :model-value="mediaId"
                    :label="
                        mediaId === null
                            ? $t('Add a picture')
                            : $t('Picture :number', {
                                  number: String(index + 1),
                              })
                    "
                    :media="props.media"
                    :brand-id="props.brandId"
                    :can-upload="props.canUpload"
                    @update:model-value="choose(index, $event)"
                />
            </div>

            <div v-if="mediaId !== null" class="flex items-center gap-2">
                <label
                    class="flex items-center gap-2 text-sm text-text-muted"
                    :for="`${uid}-primary-${index}`"
                >
                    <input
                        :id="`${uid}-primary-${index}`"
                        type="radio"
                        :name="`${uid}-primary`"
                        :value="mediaId"
                        :checked="primary === mediaId"
                        @change="primary = mediaId"
                    />
                    {{ $t('Primary') }}
                </label>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :disabled="index === 0"
                    :aria-label="
                        $t('Move :name up', { name: labelFor(mediaId) })
                    "
                    @click="move(index, -1)"
                >
                    <ChevronUp class="size-4" />
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :disabled="index === ids.length - 1"
                    :aria-label="
                        $t('Move :name down', { name: labelFor(mediaId) })
                    "
                    @click="move(index, 1)"
                >
                    <ChevronDown class="size-4" />
                </Button>
            </div>
        </div>

        <p class="text-xs text-text-muted">
            {{
                $t(
                    "The primary picture heads the venue's public page and is the one shown beside the venue on an event's page.",
                )
            }}
        </p>
    </div>
</template>

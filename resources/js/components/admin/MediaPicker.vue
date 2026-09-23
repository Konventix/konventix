<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { ImageOff, Upload, X } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, useId, watch } from 'vue';
import MediaAdminController from '@/actions/Konventix/Platform/Http/Controllers/Admin/MediaAdminController';
import InputError from '@/components/InputError.vue';
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
import {
    ACCEPT_ATTRIBUTE,
    prependCandidate,
    refuseUploadCandidate,
} from './mediaPickerUpload';

/**
 * Picks one image out of a brand's media library (ADR-0035) and yields its
 * ULID — consumers store the ULID, never a path, which is the library's
 * whole contract.
 *
 * The candidate list is supplied by the page rather than fetched here: the
 * controller already knows which brand is being edited, and a component that
 * fetched for itself would need to learn about brand scoping.
 *
 * Upload (#292): with a `brandId` and `canUpload`, the dialog also takes a
 * picture straight from disk through `MediaAdminController.quickStore` —
 * the library's own upload answered as JSON — and selects it, so an
 * operator never leaves the form for the library. The list is therefore
 * a local copy of `media`, re-seeded whenever the page hands in a new one,
 * with the fresh item put first the way the library orders them. A freshly
 * uploaded item has no `thumb` yet (`variantsPending`); the tile already
 * falls back to `url`, so nothing here waits for it.
 */
type MediaItem = Konventix.Platform.Data.MediaItemData;

type Props = {
    /** Field label, e.g. "Logo (light)". */
    label: string;
    /** The chosen media ULID, or null for "use the bundled fallback". */
    modelValue: string | null;
    /** Candidates, newest first, already narrowed to this brand's images. */
    media: MediaItem[];
    /** The brand an upload lands in; without it there is nothing to upload into. */
    brandId?: string | null;
    /** Whether this person may manage media — the route refuses regardless. */
    canUpload?: boolean;
    describedBy?: string;
};

const props = withDefaults(defineProps<Props>(), {
    brandId: null,
    canUpload: false,
    describedBy: undefined,
});
const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();

const open = ref(false);
const search = ref('');
const candidates = ref<MediaItem[]>([...props.media]);

watch(
    () => props.media,
    (media) => {
        candidates.value = [...media];
    },
);

const selected = computed(
    () => candidates.value.find((item) => item.id === props.modelValue) ?? null,
);

const matches = computed(() => {
    const term = search.value.trim().toLowerCase();

    return term === ''
        ? candidates.value
        : candidates.value.filter((item) =>
              item.originalFilename.toLowerCase().includes(term),
          );
});

function choose(id: string): void {
    emit('update:modelValue', id);
    open.value = false;
}

const uploadOffered = computed(() => props.canUpload && props.brandId !== null);
const uploadFile = ref<File | null>(null);
const uploadAlt = ref('');
const uploadError = ref<string | null>(null);
const fileInputId = `${useId()}-file`;

const upload = useHttp<
    { brand_id: string; file: File | null; alt_text: string },
    MediaItem
>({ brand_id: props.brandId ?? '', file: null, alt_text: '' });

function pickFile(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    uploadError.value = file === null ? null : refuseUploadCandidate(file);
    uploadFile.value = uploadError.value === null ? file : null;
}

function submitUpload(): void {
    if (
        uploadFile.value === null ||
        props.brandId === null ||
        upload.processing
    ) {
        return;
    }

    upload.brand_id = props.brandId;
    upload.file = uploadFile.value;
    upload.alt_text = uploadAlt.value;
    uploadError.value = null;

    upload.post(MediaAdminController.quickStore.url(), {
        onSuccess: (item) => {
            candidates.value = prependCandidate(candidates.value, item);
            uploadFile.value = null;
            uploadAlt.value = '';
            choose(item.id);
        },
        onError: (errors) => {
            const first = Object.values(errors as Record<string, string>)[0];

            uploadError.value = first ?? trans('The upload was refused.');
        },
        onNetworkError: () => {
            uploadError.value = trans(
                'The upload did not reach the server. Try again.',
            );

            return false;
        },
        onHttpException: () => {
            uploadError.value = trans(
                'The upload failed on the server. Try again.',
            );

            return false;
        },
    });
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
                    {{ selected?.originalFilename ?? $t('Bundled default') }}
                </p>
                <div class="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :aria-describedby="describedBy"
                        @click="open = true"
                    >
                        {{ $t('Choose') }}
                    </Button>
                    <Button
                        v-if="modelValue"
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="emit('update:modelValue', null)"
                    >
                        <X class="size-4" />
                        {{ $t('Clear') }}
                    </Button>
                </div>
            </div>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{{ label }}</DialogTitle>
                    <DialogDescription>
                        {{
                            uploadOffered
                                ? $t(
                                      "Images in this brand's media library, or a new one from your disk.",
                                  )
                                : $t(
                                      "Images uploaded to this brand's media library. Upload new files under Media.",
                                  )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <form
                    v-if="uploadOffered"
                    class="flex flex-col gap-2 rounded-md border border-border bg-surface-muted p-3"
                    :aria-label="$t('Upload a new image')"
                    @submit.prevent="submitUpload"
                >
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="grid min-w-0 flex-1 gap-1">
                            <Label :for="fileInputId">{{
                                $t('New image')
                            }}</Label>
                            <input
                                :id="fileInputId"
                                type="file"
                                :accept="ACCEPT_ATTRIBUTE"
                                class="text-sm"
                                :aria-describedby="
                                    uploadError
                                        ? `${fileInputId}-error`
                                        : undefined
                                "
                                :aria-invalid="uploadError ? 'true' : undefined"
                                @change="pickFile"
                            />
                        </div>
                        <div class="grid min-w-0 flex-1 gap-1">
                            <Label :for="`${fileInputId}-alt`">
                                {{ $t('Alt text (optional)') }}
                            </Label>
                            <Input
                                :id="`${fileInputId}-alt`"
                                v-model="uploadAlt"
                            />
                        </div>
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="uploadFile === null || upload.processing"
                        >
                            <Upload class="size-4" />
                            {{ $t('Upload and use') }}
                        </Button>
                    </div>
                    <div :id="`${fileInputId}-error`">
                        <InputError :message="uploadError ?? undefined" />
                    </div>
                </form>

                <Input
                    v-model="search"
                    type="search"
                    :placeholder="$t('Filter by filename')"
                    :aria-label="$t('Filter :label candidates', { label })"
                />

                <p
                    v-if="matches.length === 0"
                    class="py-8 text-center text-sm text-text-muted"
                >
                    {{ $t('Nothing to choose from yet.') }}
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

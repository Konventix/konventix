<script setup lang="ts">
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Confirmation } from './useConfirmation';

/**
 * The question `useConfirmation()` asks, on `@/components/ui/dialog`, whose
 * primitive traps focus while open and hands it back on close (ADR-0055).
 * The corner close button is left out because its screen-reader label is
 * the primitive's English "Close"; the cancel button in the footer, in the
 * caller's language, does the same job.
 */
const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    confirmation: Confirmation | null;
}>();

const emit = defineEmits<{ confirm: [] }>();

// The last question asked, kept while the dialog animates closed, so its
// text does not vanish a frame before the dialog does.
const shown = ref<Confirmation | null>(null);

watch(
    () => props.confirmation,
    (confirmation) => {
        if (confirmation !== null) {
            shown.value = confirmation;
        }
    },
    { immediate: true },
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent :show-close-button="false">
            <template v-if="shown">
                <DialogHeader>
                    <DialogTitle>{{ shown.title }}</DialogTitle>
                    <DialogDescription>
                        {{ shown.description }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary">
                            {{ shown.cancelLabel }}
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        :variant="
                            shown.destructive === false
                                ? 'default'
                                : 'destructive'
                        "
                        @click="emit('confirm')"
                    >
                        {{ shown.confirmLabel }}
                    </Button>
                </DialogFooter>
            </template>
        </DialogContent>
    </Dialog>
</template>

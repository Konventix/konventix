<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    acknowledge,
    canFinish,
    finish,
    isRevealing,
} from './recoveryCodesReveal';
import type { RecoveryCodesReveal } from './recoveryCodesReveal';

/**
 * The one time the recovery codes are on screen. Not dismissible: Escape, a
 * click outside and the close button are all off, so the only way out is the
 * Done button, and that stays disabled until "I saved my recovery codes" is
 * ticked. The gate itself is `recoveryCodesReveal.ts`; this is its markup.
 */
const state = defineModel<RecoveryCodesReveal>({ required: true });

const open = computed(() => isRevealing(state.value));
const done = computed(() => canFinish(state.value));

function onAcknowledge(saved: boolean | 'indeterminate'): void {
    state.value = acknowledge(state.value, saved === true);
}

function onDone(): void {
    state.value = finish(state.value);
}
</script>

<template>
    <Dialog :open="open">
        <DialogContent
            :show-close-button="false"
            @escape-key-down.prevent
            @pointer-down-outside.prevent
            @interact-outside.prevent
        >
            <DialogHeader class="space-y-3">
                <DialogTitle>{{ $t('Save your recovery codes') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'These codes are shown once. Store them somewhere safe — each can be used one time to sign in if you lose access to your authenticator app.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <ul
                class="grid grid-cols-2 gap-1 rounded-lg border border-border bg-subtle p-4 font-mono text-sm"
                data-test="recovery-codes"
            >
                <li v-for="code in state.codes" :key="code">
                    {{ code }}
                </li>
            </ul>

            <Label
                for="recovery-codes-saved"
                class="flex items-center space-x-3"
            >
                <Checkbox
                    id="recovery-codes-saved"
                    :model-value="state.acknowledged"
                    data-test="recovery-codes-saved"
                    @update:model-value="onAcknowledge"
                />
                <span>{{ $t('I saved my recovery codes') }}</span>
            </Label>

            <DialogFooter>
                <Button
                    type="button"
                    :disabled="!done"
                    data-test="recovery-codes-done"
                    @click="onDone"
                >
                    {{ $t('Done') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

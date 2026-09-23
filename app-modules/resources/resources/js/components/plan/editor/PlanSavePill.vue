<script setup lang="ts">
import { Check, LoaderCircle, TriangleAlert } from '@lucide/vue';
import type { SaveStatus } from './usePlanSave';

/**
 * Unsaved / Saving / Saved / Error with the server text (design §8.3,
 * ADR-0038's "Unsaved changes" note in the same `role="status"`). Nothing
 * for a clean plan.
 */
defineProps<{ status: SaveStatus; message: string | null }>();
</script>

<template>
    <span
        role="status"
        class="inline-flex max-w-md items-center gap-1.5 text-sm"
    >
        <template v-if="status === 'unsaved'">
            <span class="text-warning">{{ $t('Unsaved changes') }}</span>
        </template>
        <template v-else-if="status === 'saving'">
            <LoaderCircle
                class="size-4 animate-spin text-text-muted"
                aria-hidden="true"
            />
            <span class="text-text-muted">{{ $t('Saving…') }}</span>
        </template>
        <template v-else-if="status === 'saved'">
            <Check class="size-4 text-success" aria-hidden="true" />
            <span class="text-success">{{ $t('Saved') }}</span>
        </template>
        <template v-else-if="status === 'error'">
            <TriangleAlert
                class="size-4 shrink-0 text-danger"
                aria-hidden="true"
            />
            <span class="truncate text-danger">{{
                message ?? $t('Save failed')
            }}</span>
        </template>
    </span>
</template>

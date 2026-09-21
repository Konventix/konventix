<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

/**
 * A multi-line venue field — the description, how to get there, what to
 * know at the door. One component because the venue form has three of
 * them, spelled three times before this, and an empty box means "nothing
 * to say" rather than an empty string: the column is nullable and the
 * public page renders the section only when there is one.
 *
 * There is no shared `Textarea` primitive in `@/components/ui` yet; when
 * one arrives this is the single place that changes.
 */
const model = defineModel<string | null>({ required: true });

withDefaults(
    defineProps<{
        id: string;
        label: string;
        rows?: number;
        error?: string;
    }>(),
    { rows: 3, error: undefined },
);
</script>

<template>
    <div class="grid gap-2">
        <Label :for="id">{{ label }}</Label>
        <textarea
            :id="id"
            :value="model ?? ''"
            :rows="rows"
            class="rounded-md border border-border bg-surface p-3 text-sm"
            @input="
                model = ($event.target as HTMLTextAreaElement).value || null
            "
        ></textarea>
        <InputError :message="error" />
    </div>
</template>

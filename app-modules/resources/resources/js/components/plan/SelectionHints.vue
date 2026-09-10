<script setup lang="ts">
/**
 * Two live regions (design §7.4, ADR-0055: state changes are announced,
 * never ticking values): what is selected, and why the last tap did
 * nothing. Both `role="status"` — polite, and read in full when the text
 * changes in place.
 */
defineProps<{
    announcement: string;
    hint: string | null;
}>();
</script>

<template>
    <div class="flex flex-col gap-1 text-sm">
        <p role="status" class="text-text-muted">{{ announcement }}</p>
        <p role="status" class="min-h-5 text-warning">{{ hint ?? '' }}</p>
    </div>
</template>

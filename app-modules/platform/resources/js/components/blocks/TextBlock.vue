<script setup lang="ts">
defineProps<{
    heading: string | null;
    body: string;
}>();
</script>

<template>
    <section class="flex flex-col gap-2">
        <h2 v-if="heading" class="text-xl font-medium text-text">
            {{ heading }}
        </h2>
        <p class="text-sm leading-relaxed whitespace-pre-line text-text-muted">
            {{ body }}
        </p>
    </section>
</template>

<script setup lang="ts">
import AuthLayout from '@/layouts/auth/AuthSimpleLayout.vue';

// `title` and `description` are catalog keys the page declared in
// `defineOptions({ layout })` at module scope, before any catalog is loaded;
// they are translated here, where they render (ADR-0068).
const { title = '', description = '' } = defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <AuthLayout
        :title="title ? $t(title) : ''"
        :description="description ? $t(description) : ''"
    >
        <slot />
    </AuthLayout>
</template>

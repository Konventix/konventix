<script setup lang="ts">
import { computed } from 'vue';
import { SidebarInset } from '@/components/ui/sidebar';
import type { AppVariant } from '@/types';

type Props = {
    variant?: AppVariant;
    class?: string;
};

const props = withDefaults(defineProps<Props>(), {
    variant: 'sidebar',
});
const className = computed(() => props.class);
</script>

<template>
    <!--
        `id`/`tabindex` are the skip link's target (see SkipToContent.vue).
        `tabindex="-1"` keeps the landmark out of the tab order while making
        it focusable by the fragment link — a plain `#main-content` scrolls
        without moving focus, so the next Tab returns to the navigation.
    -->
    <SidebarInset
        v-if="props.variant === 'sidebar'"
        id="main-content"
        tabindex="-1"
        :class="className"
    >
        <slot />
    </SidebarInset>
    <main
        v-else
        id="main-content"
        tabindex="-1"
        class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl"
        :class="className"
    >
        <slot />
    </main>
</template>
